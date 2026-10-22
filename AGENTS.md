# Repository agent instructions

## Product delivery

- Do not develop or ship product functionality behind runtime feature flags,
  environment rollout switches, workspace allowlists, or hidden UI branches.
- Completed functionality is enabled by default. Use authorization, validated
  configuration, readiness checks, and domain invariants to decide whether a
  specific operation is permitted.
- Remove superseded code paths instead of retaining a flag-controlled legacy
  implementation.
- Narrow emergency safety brakes are allowed for irreversible or paid external
  actions, and test-only controls are allowed for deterministic fixtures. They
  must not hide product functionality, must have a safe default, and must be
  documented and tested as operational controls rather than rollout flags.
