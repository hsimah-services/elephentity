fn main() {
    let arguments: Vec<String> = std::env::args().skip(1).collect();

    match arguments.as_slice() {
        [flag] if flag == "--version" => {
            println!("eleph {}", env!("CARGO_PKG_VERSION"));
        }
        _ => {
            eprintln!("usage: eleph --version");
            eprintln!(
                "The compiler commands arrive in later steps; see hsimah-services/elephentity#115."
            );
            std::process::exit(2);
        }
    }
}
