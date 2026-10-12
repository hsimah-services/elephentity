use std::io::IsTerminal;

fn main() {
    let arguments: Vec<String> = std::env::args().collect();
    let attached = std::io::stdout().is_terminal() && std::io::stderr().is_terminal();

    let code = eleph_compiler::cli::run(
        &arguments,
        &mut std::io::stdout(),
        &mut std::io::stderr(),
        attached,
    );

    std::process::exit(code);
}
