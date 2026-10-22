# Lessons from GraphQL

- Confirm every emitted class exists in the generic runtime; the old WPGraphQL verifier
  class does not.
- Root single and collection fields need distinct names; an absent plural must not default
  to the singular (it defaults to `<Singular>Collection`).
- Root entries also drive delete mutations; clearing roots has consequences beyond queries.
- A published query's return entity must itself be exposed.
- Executing the generated schema against the host library catches what snapshots cannot.
- Generated verifiers may be anonymous classes implementing the core contract when the
  integration runtime has no verifier class yet.
- A fixture description may mention a platform without depending on it; test emitted
  namespaces, not arbitrary prose substrings.
