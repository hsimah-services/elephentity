# HasSlug

Adds a required string field named `slug`, with a maximum declared storage length
of 120. Generated mutation APIs allow it on creation and omit an update setter.

Apply with `use: [HasSlug]`. No custom implementation or other pattern is required.

This does not generate slugs, enforce a character format, or make slugs unique.
Choose those rules in your application-owned copy before generation. A pattern's
members cannot be redeclared by the entity that uses it. Existing rows need an
application migration before this field can be required.
