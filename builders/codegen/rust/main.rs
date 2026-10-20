//! The orchestrator deliberately treats schema and provides as opaque JSON values.
use serde_json::{json, Map, Value};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeSet,
    env, fs,
    io::{self, Read, Seek, Write},
    path::{Component, Path, PathBuf},
    process::{Command, Stdio},
};
type Result<T> = std::result::Result<T, String>;
const IR: &str = "1.2";
fn string(v: &Value) -> &str {
    v.as_str().unwrap_or("")
}
fn display(v: &Value) -> String {
    match v {
        Value::String(s) => s.clone(),
        Value::Object(_) | Value::Array(_) => "array".into(),
        _ => v.to_string(),
    }
}
fn versions(v: &Value) -> Result<()> {
    if v["elephentity"].as_u64() != Some(1) {
        return Err(format!(
            "Protocol version mismatch: this build speaks 1, the other side speaks {}.",
            display(&v["elephentity"])
        ));
    }
    if v["irVersion"] != IR {
        return Err(format!("IR version mismatch: this build emits {IR}, the other side speaks {}. There is no compatibility guarantee before 1.0; upgrade whichever side is behind.", display(&v["irVersion"])));
    }
    Ok(())
}
fn parse(s: &str) -> Result<Value> {
    let v: Value = serde_json::from_str(s).map_err(|e| format!("Not valid JSON: {e}"))?;
    if !v.is_object() {
        return Err("Expected a JSON object.".into());
    }
    Ok(v)
}
#[derive(Clone)]
struct Target {
    name: String,
    output: String,
    directory: PathBuf,
    builder: String,
    settings: Value,
}
impl Target {
    /// What to look for on disk. `"eleph-gen-csharp@0.1.0"` pins a release for `eleph install`
    /// and is found as `eleph-gen-csharp`; a path is a path and is never split, because `@` is
    /// legal in one.
    fn program(&self) -> &str {
        match self.builder.split_once('@') {
            Some((name, version))
                if !self.builder.contains('/') && !name.is_empty() && !version.is_empty() =>
            {
                name
            }
            _ => &self.builder,
        }
    }
    /// The settings a builder is handed: everything but the keys this program owns.
    fn config(&self) -> Value {
        let mut settings = self.settings.clone();
        if let Some(settings) = settings.as_object_mut() {
            settings.remove("source");
        }
        settings
    }
    /// Where a pinned builder's releases are published, when the project says so instead of
    /// relying on the installer's table of known builders. Never sent to a builder.
    fn source(&self) -> Option<&str> {
        self.settings["source"].as_str().filter(|s| !s.is_empty())
    }
}
struct Config {
    spec: String,
    targets: Vec<Target>,
    builders: String,
}
impl Config {
    fn load(root: &str) -> Result<Self> {
        let path = format!("{}/eleph.json", root.trim_end_matches('/'));
        let contents = fs::read_to_string(&path).map_err(|_| {
            format!("No eleph.json in \"{root}\". It needs \"spec\" and a \"targets\" block.")
        })?;
        let v = parse(&contents)?;
        let mut errors = vec![];
        if string(&v["spec"]).is_empty() {
            errors.push("\"spec\" must be a non-empty string.".to_owned());
        }
        let mut targets = vec![];
        if let Some(block) = v["targets"].as_object().filter(|b| !b.is_empty()) {
            for (name, settings) in block {
                if !settings.is_object() {
                    errors.push(format!("Target \"{name}\" must be an object."));
                    continue;
                }
                let output = string(&settings["output"]);
                let builder = string(&settings["builder"]);
                if output.is_empty() {
                    errors.push(format!(
                        "Target \"{name}\" must set \"output\" to a non-empty string."
                    ));
                    continue;
                }
                if builder.is_empty() {
                    errors.push(format!("Target \"{name}\" must set \"builder\" to the program that generates it. Elephentity compiles the spec and generates nothing itself, so a target with no builder is one nothing can produce."));
                    continue;
                }
                targets.push(Target {
                    name: name.clone(),
                    output: output.into(),
                    directory: output_path(root, output)?,
                    builder: builder.into(),
                    settings: settings.clone(),
                });
            }
        } else {
            errors.push("\"targets\" must be an object with at least one target in it.".into());
        }
        let mut directories: Map<String, Value> = Map::new();
        for t in &targets {
            directories
                .entry(t.directory.to_string_lossy().into_owned())
                .or_insert(json!([]))
                .as_array_mut()
                .unwrap()
                .push(json!(t.name));
        }
        for (dir, names) in directories {
            let names = names.as_array().unwrap();
            if names.len() > 1 {
                errors.push(format!("Targets {} all write to \"{dir}\". Each target needs its own output directory, because generating one deletes whatever it does not produce.", names.iter().map(string).collect::<Vec<_>>().join(" and ")));
            }
        }
        if !errors.is_empty() {
            return Err(format!("{path} is not usable:\n  {}", errors.join("\n  ")));
        }
        Ok(Self {
            spec: string(&v["spec"]).into(),
            targets,
            builders: v["builders"]
                .as_str()
                .filter(|s| !s.is_empty())
                .unwrap_or("tools/builders")
                .into(),
        })
    }
    fn reserved(&self, target: &Target) -> Vec<String> {
        self.targets
            .iter()
            .filter(|t| t.name != target.name)
            .filter_map(|t| {
                t.directory
                    .strip_prefix(&target.directory)
                    .ok()
                    .map(|p| p.to_string_lossy().into_owned())
            })
            .collect()
    }
    fn resolve(&self, root: &str, target: &Target) -> Result<PathBuf> {
        let mut looked = vec![];
        let mut candidates = vec![];
        if target.builder.contains('/') {
            let p = if target.builder.starts_with('/') {
                target.builder.clone()
            } else {
                format!("{root}/{}", target.builder)
            };
            looked.push(p.clone());
            candidates.push(PathBuf::from(p));
        } else {
            let p = format!(
                "{root}/{}/{}",
                self.builders.trim_matches('/'),
                target.program()
            );
            looked.push(p.clone());
            candidates.push(PathBuf::from(p));
            for dir in env::split_paths(&env::var_os("PATH").unwrap_or_default()) {
                if !dir.as_os_str().is_empty() {
                    candidates.push(dir.join(target.program()));
                }
            }
            looked.push("anywhere on PATH".into());
        }
        for path in candidates {
            if !path.is_file() {
                continue;
            }
            #[cfg(unix)]
            {
                use std::os::unix::fs::PermissionsExt;
                if fs::metadata(&path)
                    .map_err(|e| e.to_string())?
                    .permissions()
                    .mode()
                    & 0o111
                    == 0
                {
                    return Err(format!("The builder for target \"{}\" is at {} but is not executable. Try `chmod +x {}`.", target.name, path.display(), path.display()));
                }
            }
            return Ok(path);
        }
        Err(format!("No builder \"{}\" for target \"{}\". Looked in:\n  {}\nInstall it and put it where one of those points; Elephentity does not fetch builders. (`eleph install` places a builder pinned as \"name@version\" in eleph.json.)", target.program(), target.name, looked.join("\n  ")))
    }
}
// Resolve existing symlinks before walking parents; new directories need no mkdir to compare.
fn output_path(root: &str, output: &str) -> Result<PathBuf> {
    let root = fs::canonicalize(root).map_err(|e| format!("Cannot resolve project {root}: {e}"))?;
    let mut resolved = PathBuf::new();
    for component in root.join(output).components() {
        match component {
            Component::ParentDir => {
                resolved.pop();
            }
            Component::CurDir => {}
            _ => {
                resolved.push(component.as_os_str());
                match fs::canonicalize(&resolved) {
                    Ok(path) => resolved = path,
                    Err(e) if e.kind() == io::ErrorKind::NotFound => {}
                    Err(e) => {
                        return Err(format!("Cannot resolve output {}: {e}", resolved.display()))
                    }
                }
            }
        }
    }
    Ok(resolved)
}
fn exchange(path: &Path, request: &Value) -> Result<Value> {
    // A temporary input file and concurrently drained output streams avoid pipe deadlocks.
    let mut input = tempfile::tempfile().map_err(|e| e.to_string())?;
    input
        .write_all(request.to_string().as_bytes())
        .map_err(|e| e.to_string())?;
    input.rewind().map_err(|e| e.to_string())?;
    let output = Command::new(path)
        .stdin(Stdio::from(input))
        .output()
        .map_err(|e| format!("Could not run {}: {e}", path.display()))?;
    if !output.status.success() {
        return Err(format!(
            "{} exited {}.\n  {}",
            path.display(),
            output.status.code().unwrap_or(-1),
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    let v = parse(&String::from_utf8_lossy(&output.stdout))?;
    versions(&v)?;
    Ok(v)
}
#[derive(Clone)]
struct File {
    path: String,
    body: String,
}
fn files(v: &Value, error: &str) -> Result<Vec<File>> {
    let list = v.as_array().ok_or_else(|| error.to_owned())?;
    list.iter()
        .map(|f| {
            let path = f["path"]
                .as_str()
                .filter(|s| !s.is_empty())
                .ok_or_else(|| error.to_owned())?;
            let body = f["body"].as_str().ok_or_else(|| error.to_owned())?;
            if path.contains("..") || Path::new(path).is_absolute() || path.contains('\0') {
                return Err(format!(
                    "File path \"{path}\" escapes the output directory."
                ));
            }
            Ok(File {
                path: path.into(),
                body: body.into(),
            })
        })
        .collect()
}
fn strings(v: &Value, name: &str) -> Result<Vec<String>> {
    if v.is_null() {
        return Ok(vec![]);
    }
    v.as_array()
        .ok_or_else(|| format!("\"{name}\" must be a list of strings."))?
        .iter()
        .map(|v| {
            v.as_str()
                .map(str::to_owned)
                .ok_or_else(|| format!("\"{name}\" must be a list of strings."))
        })
        .collect()
}
fn digest(path: &str, body: &str) -> String {
    format!(
        "sha256:{:x}",
        Sha256::digest(format!("{path}\n{body}").as_bytes())
    )
}
fn sign(path: &str, body: &str, style: &str) -> String {
    let hash = digest(path, body);
    if style == "php" {
        format!("<?php\n\ndeclare(strict_types=1);\n\n/*\n * GENERATED BY Elephentity — DO NOT EDIT.\n *\n * Regenerate with `eleph generate`. This file is machine-owned: edits are\n * detected by the build and rejected.\n *\n * path:   {path}\n * digest: {hash}\n */\n\n{body}")
    } else {
        format!("// GENERATED BY Elephentity — DO NOT EDIT.\n//\n// Regenerate with `eleph generate`. This file is machine-owned: edits are\n// detected by the build and rejected.\n//\n// path:   {path}\n// digest: {hash}\n\n{body}")
    }
}
fn tampered(path: &str, contents: &str, style: &str) -> bool {
    let count = if style == "php" { 14 } else { 8 };
    let prefix = if style == "php" {
        " * digest: "
    } else {
        "// digest: "
    };
    let declared = contents
        .split('\n')
        .take(count)
        .find_map(|s| s.strip_prefix(prefix));
    declared.is_some_and(|h| {
        h != digest(
            path,
            &contents
                .split('\n')
                .skip(count)
                .collect::<Vec<_>>()
                .join("\n"),
        )
    })
}
#[derive(Default)]
struct Report {
    created: Vec<String>,
    updated: Vec<String>,
    unchanged: Vec<String>,
    deleted: Vec<String>,
    tampered: Vec<String>,
}
impl Report {
    fn changes(&self) -> usize {
        self.created.len() + self.updated.len() + self.deleted.len()
    }
}
struct Plan {
    name: String,
    directory: PathBuf,
    files: Vec<File>,
    style: String,
    extensions: Vec<String>,
    reserved: Vec<String>,
}
fn scan(root: &Path, dir: &Path, result: &mut Vec<String>) -> Result<()> {
    if !dir.exists() {
        return Ok(());
    }
    for entry in fs::read_dir(dir).map_err(|e| e.to_string())? {
        let entry = entry.map_err(|e| e.to_string())?;
        if entry.file_type().map_err(|e| e.to_string())?.is_dir() {
            scan(root, &entry.path(), result)?;
        } else {
            result.push(
                entry
                    .path()
                    .strip_prefix(root)
                    .unwrap()
                    .to_string_lossy()
                    .into_owned(),
            );
        }
    }
    Ok(())
}
fn apply(plan: &Plan, check: bool) -> Result<Report> {
    let mut report = Report::default();
    let mut expected = BTreeSet::new();
    for f in &plan.files {
        expected.insert(f.path.clone());
        let path = plan.directory.join(&f.path);
        let signed = sign(&f.path, &f.body, &plan.style);
        match fs::read_to_string(&path) {
            Ok(existing) if existing == signed => {
                report.unchanged.push(f.path.clone());
                continue;
            }
            Ok(existing) => {
                report.updated.push(f.path.clone());
                if tampered(&f.path, &existing, &plan.style) {
                    report.tampered.push(f.path.clone());
                }
            }
            Err(e) if e.kind() == io::ErrorKind::NotFound => report.created.push(f.path.clone()),
            Err(e) => return Err(format!("Cannot read {}: {e}", path.display())),
        }
        if !check {
            fs::create_dir_all(path.parent().unwrap()).map_err(|e| e.to_string())?;
            fs::write(path, signed).map_err(|e| e.to_string())?;
        }
    }
    let mut existing = vec![];
    scan(&plan.directory, &plan.directory, &mut existing)?;
    for path in existing {
        if !expected.contains(&path)
            && plan.extensions.iter().any(|ext| {
                Path::new(&path)
                    .extension()
                    .is_some_and(|x| x == ext.as_str())
            })
            && !plan
                .reserved
                .iter()
                .any(|dir| path.starts_with(&format!("{}/", dir.trim_matches('/'))))
        {
            report.deleted.push(path);
        }
    }
    report.deleted.sort();
    if !check {
        for path in &report.deleted {
            fs::remove_file(plan.directory.join(path)).map_err(|e| e.to_string())?;
        }
    }
    Ok(report)
}
fn run() -> Result<i32> {
    let mut args = env::args().skip(1);
    let command = args.next().unwrap_or_else(|| "help".into());
    let mut root = ".".to_owned();
    let mut check = false;
    let mut selected: Option<String> = None;
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--project" | "-p" => {
                root = args.next().ok_or("--project must be a directory path.")?
            }
            "--targets" | "-t" => {
                selected = Some(
                    args.next()
                        .ok_or("--targets needs at least one target name.")?,
                )
            }
            "--check" => check = true,
            "--no-ansi" | "--no-interaction" | "-n" => {}
            _ if arg.starts_with("--project=") => root = arg[10..].into(),
            _ if arg.starts_with("--targets=") => selected = Some(arg[10..].into()),
            _ => return Err(format!("Unknown option {arg}.")),
        }
    }
    if command == "help" || command == "--help" || command == "list" {
        println!("eleph-codegen\n\nCommands: generate, describe, targets, doctor\nOptions: --project PATH, --targets NAMES, --check");
        return Ok(0);
    }
    let mut request = Value::Null;
    let mut contributions = Map::new();
    if command == "generate" {
        let mut input = String::new();
        io::stdin()
            .read_to_string(&mut input)
            .map_err(|e| e.to_string())?;
        if input.trim().is_empty() {
            println!("Expected a compiled spec on stdin. `eleph-codegen generate` is run by `eleph generate`, which compiles the specs and pipes them in.");
            return Ok(2);
        }
        let parsed: Result<Value> = (|| {
            let r = parse(&input)?;
            versions(&r)?;
            if !r["schema"].is_object() {
                return Err("The request carries no schema.".into());
            }
            Ok(r)
        })();
        request = match parsed {
            Ok(r) => r,
            Err(e) => {
                println!("{e}");
                return Ok(2);
            }
        };
        if let Some(v) = request.get("files") {
            let block = v
                .as_object()
                .ok_or("\"files\" must be an object keyed by target.")?;
            for (name, value) in block {
                if name.is_empty() {
                    return Err("Every entry in \"files\" must be keyed by target name.".into());
                }
                files(value, &format!("Every file contributed to \"{name}\" must be an object with \"path\" and \"body\"."))?;
                contributions.insert(name.clone(), value.clone());
            }
        }
    }
    let config = Config::load(&root)?;
    if command == "targets" {
        let targets: Map<String, Value> = config
            .targets
            .iter()
            .map(|t| {
                let mut described = json!({"output": t.output, "builder": t.builder});
                if let Some(source) = t.source() {
                    described["source"] = json!(source);
                }
                (t.name.clone(), described)
            })
            .collect();
        println!("{}", json!({"spec": config.spec, "targets": targets}));
        return Ok(0);
    }
    let mut targets = config.targets.iter().collect::<Vec<_>>();
    if let Some(names) = &selected {
        let names = names
            .split(',')
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .collect::<Vec<_>>();
        if names.is_empty() {
            println!("--targets needs at least one target name.");
            return Ok(2);
        }
        let unknown = names
            .iter()
            .filter(|n| !config.targets.iter().any(|t| &t.name == *n))
            .copied()
            .collect::<Vec<_>>();
        if !unknown.is_empty() {
            println!(
                "Unknown target(s): {}. This project configures: {}.",
                unknown.join(", "),
                config
                    .targets
                    .iter()
                    .map(|t| t.name.as_str())
                    .collect::<Vec<_>>()
                    .join(", ")
            );
            return Ok(2);
        }
        targets.retain(|t| names.contains(&t.name.as_str()));
    }
    let mut errors = vec![];
    let mut plans = vec![];
    let mut descriptions = Map::new();
    if command == "doctor" {
        println!("eleph-codegen speaks protocol 1, IR {IR}.");
    }
    if !["generate", "describe", "doctor"].contains(&command.as_str()) {
        return Err(format!("Unknown command {command}."));
    }
    for target in targets {
        let path = match config.resolve(&root, target) {
            Ok(p) => p,
            Err(e) => {
                errors.push(e);
                continue;
            }
        };
        if command == "doctor" {
            println!(
                "  {} → {} (writes {})",
                target.name,
                path.display(),
                target.output
            );
            continue;
        }
        let output = target.directory.to_string_lossy().into_owned();
        let payload = if command == "describe" {
            json!({"elephentity": 1, "irVersion": IR, "request": "describe", "target": target.name, "config": target.config()})
        } else {
            json!({"elephentity": 1, "irVersion": IR, "request": "generate", "target": target.name, "config": target.config(), "outputDirectory": output, "schema": request["schema"]})
        };
        let response = match exchange(&path, &payload) {
            Ok(r) => r,
            Err(e) => {
                errors.push(if command == "describe" { format!("[{}] The {} builder could not describe itself: {e}\n  A builder must answer a \"describe\" request; one written before describe existed reads it as a generate and fails on the missing schema.", target.name, target.name) } else { format!("[{}] The {} builder sent something unusable: {e}", target.name, target.name) });
                continue;
            }
        };
        if command == "describe" {
            if response.get("provides").is_some_and(|p| !p.is_object()) {
                errors.push(format!("[{}] \"provides\" must be an object.", target.name));
            } else {
                descriptions.insert(
                    target.name.clone(),
                    response.get("provides").cloned().unwrap_or(json!({})),
                );
            }
            continue;
        }
        let decoded = (|| {
            let style = string(&response["headerStyle"]);
            if !["php", "line-comment"].contains(&style) {
                return Err(format!(
                    "The response declares header style {}, which this build does not know.",
                    response["headerStyle"]
                ));
            }
            let problems = strings(&response["errors"], "errors")?;
            if !problems.is_empty() {
                return Err(problems.join("\n"));
            }
            let mut generated = files(
                &response["files"],
                "Every file must be an object with \"path\" and \"body\".",
            )?;
            if let Some(contributed) = contributions.get(&target.name) {
                generated.extend(files(contributed, "Invalid contributed file.")?);
            }
            Ok(Plan {
                name: target.name.clone(),
                directory: output.into(),
                files: generated,
                style: style.into(),
                extensions: strings(&response["extensions"], "extensions")?,
                reserved: config.reserved(target),
            })
        })();
        match decoded {
            Ok(p) => plans.push(p),
            Err(e) => errors.push(format!("[{}] {e}", target.name)),
        }
    }
    if !errors.is_empty() {
        if command == "describe" {
            eprintln!("{}", errors.join("\n"));
        } else {
            println!(
                "{} problem(s) with the targets; nothing was generated.\n  {}",
                errors.len(),
                errors.join("\n  ")
            );
        }
        return Ok(1);
    }
    if command == "describe" {
        println!(
            "{}",
            json!({"elephentity": 1, "irVersion": IR, "targets": descriptions})
        );
        return Ok(0);
    }
    if command == "doctor" {
        println!("{} target(s) ready.", config.targets.len());
        return Ok(0);
    }
    let mut dirty = 0;
    let mut unchanged = 0;
    for plan in &plans {
        let report = apply(plan, check)?;
        unchanged += report.unchanged.len();
        if check {
            if report.changes() > 0 {
                dirty += 1;
                println!("{} — {} file(s) differ", plan.name, report.changes());
                for (heading, paths) in [
                    ("Hand-edited", &report.tampered),
                    ("Would be created", &report.created),
                    ("Would be updated", &report.updated),
                    ("No longer produced by the schema", &report.deleted),
                ] {
                    if !paths.is_empty() {
                        println!("{heading}:\n  {}", paths.join("\n  "));
                    }
                }
            }
        } else {
            for path in &report.tampered {
                println!(
                    "[{}] {path} had been edited by hand; it has been regenerated.",
                    plan.name
                );
            }
            println!(
                "{}: {} file(s): {} created, {} updated, {} unchanged, {} removed.",
                plan.name,
                plan.files.len(),
                report.created.len(),
                report.updated.len(),
                report.unchanged.len(),
                report.deleted.len()
            );
        }
    }
    if check {
        if dirty == 0 {
            println!(
                "Every target is up to date ({} target(s), {unchanged} files).",
                plans.len()
            );
        } else {
            println!("{dirty} of {} target(s) are out of date.\nRun eleph generate and commit the result.", plans.len());
            return Ok(1);
        }
    } else {
        println!("{} target(s) generated.", plans.len());
    }
    Ok(0)
}
fn main() {
    match run() {
        Ok(code) => std::process::exit(code),
        Err(e) => {
            eprintln!("{e}");
            std::process::exit(1);
        }
    }
}
