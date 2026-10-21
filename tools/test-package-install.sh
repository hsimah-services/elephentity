#!/usr/bin/env bash
# Verify the actual Composer consumer layout, independently of root dev autoload: the runtime and
# the builders from Composer, and the Rust `eleph` (built here from compiler/) as the compiler.
set -euo pipefail
source_root="$(cd "$(dirname "$0")/.." && pwd)"
cargo build --release --locked --manifest-path "$source_root/compiler/Cargo.toml"
eleph="$source_root/compiler/target/release/eleph"
project="$(mktemp -d)"
trap 'rm -rf "$project"' EXIT
cd "$project"
cat > composer.json <<'JSON'
{
  "name": "elephentity/package-install-check",
  "require": {
    "elephentity/elephentity": "^0.17",
    "elephentity/memory": "^0.17"
  },
  "require-dev": {
    "elephentity/codegen": "^0.7",
    "elephentity/codegen-php": "^0.8.1"
  },
  "autoload": {"psr-4": {"InstallCheck\\Entity\\": "generated/"}}
}
JSON
if [ "${1:-}" != --published ]; then
  # Paths model the future release without installing the monorepo as a library. The PHP packages
  # share one version (tools/family-version); a pull request checkout has no branch Composer could
  # read it from. codegen-php is versioned on its own, so it gets a stand-in for its next release.
  family="$("$source_root/tools/family-version")"
  php -r '
    $file = "composer.json";
    $config = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $packages = [
        ["elephentity/elephentity", "", $argv[2]],
        ["elephentity/runtime", "/packages/runtime", $argv[2]],
        ["elephentity/memory", "/packages/memory", $argv[2]],
        ["elephentity/codegen-php", "/builders/codegen-php", "0.8.99"],
    ];
    foreach ($packages as [$name, $path, $version]) {
        $config["repositories"][] = ["type" => "path", "url" => $argv[1] . $path,
            "options" => ["symlink" => false, "versions" => [$name => $version]]];
    }
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' "$source_root" "$family"
fi
composer update --no-interaction --prefer-dist
cargo build --release --locked --manifest-path vendor/elephentity/codegen/Cargo.toml
cargo build --release --locked --manifest-path vendor/elephentity/codegen-php/Cargo.toml
mkdir -p spec/entities
cat > spec/project.yml <<'YAML'
project: InstallCheck
storage:
  driver: memory
YAML
cat > spec/entities/Note.yml <<'YAML'
entity: Note
storage:
  table: note
fields:
  body:
    type: text
    required: true
YAML
# eleph-codegen is found in vendor/bin, where Composer put it; eleph-gen-memory ships with the
# memory driver it declares.
cat > eleph.json <<'JSON'
{
  "spec": "spec",
  "targets": {
    "php": {"builder": "vendor/bin/eleph-gen-php", "output": "generated", "namespace": "InstallCheck\\Entity", "typeNamespace": "InstallCheck\\Type"},
    "memory": {"builder": "vendor/bin/eleph-gen-memory", "output": "generated/memory"}
  }
}
JSON
"$eleph" fmt
"$eleph" validate
"$eleph" generate
"$eleph" generate --check
"$eleph" check
php -r '
    require "vendor/autoload.php";
    foreach (["Eleph\\Memory\\MemoryAdaptor", "InstallCheck\\Entity\\Note\\Note"] as $class) {
        if (!class_exists($class)) { throw new RuntimeException("Missing " . $class); }
    }
    foreach (["elephentity/wordpress", "elephentity/wpgraphql"] as $package) {
        if (Composer\InstalledVersions::isInstalled($package)) { throw new RuntimeException("Unexpected " . $package); }
    }
    if (is_dir("vendor/elephentity/elephentity")) { throw new RuntimeException("Metapackage installed source files"); }
'
composer install --no-dev --no-interaction
php -r '
    require "vendor/autoload.php";
    if (!class_exists("Eleph\\Memory\\MemoryAdaptor")) { throw new RuntimeException("Missing production adaptor"); }
'
printf 'Standalone install, generation, conformance and production dependency checks passed.\n'
