//! `rust/ir.rs` is a byte-identical copy of another repository's file (SOURCE.md). The hash recorded
//! there is the proof, so an edit made here by accident, or a copy that was updated without the
//! record, fails this instead of drifting.

use sha2::{Digest, Sha256};

#[test]
fn ir_rs_is_the_copy_source_md_describes() {
    let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"));
    let digest: String = Sha256::digest(std::fs::read(root.join("rust/ir.rs")).unwrap())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect();
    let source = std::fs::read_to_string(root.join("SOURCE.md")).unwrap();

    assert!(
        source.contains(&format!("`{digest}`")),
        "rust/ir.rs hashes to {digest}, which SOURCE.md does not record. Copy the file again, or update the record and say why."
    );
}
