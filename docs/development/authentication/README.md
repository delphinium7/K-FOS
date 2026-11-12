# K-FOS Authentication Implementation

## Development scope
- Local-development-only KFOS_ADMIN test identity
- Real Employee ID/password authentication
- Add User
- Activate User
- Deactivate User
- Backend System Administrator authorization

## Binding requirements
- AUTH-005: protected server-side initialization
- AUTH-025: newly created accounts Active
- AUTH-026: separate manual account status control
- AUTH-027: revoke sessions after deactivation
- AUTH-029–032/085: administrator protections
- AUTH-091: real named employee for production administrator
- AUTH-092: verified recovery before production
- AUTH-DEV-ADMIN-001: local-only shared test account

## Safety
- No production access for KFOS_ADMIN
- No hardcoded administrator password
- No real personnel data in local testing
- No automatic user deactivation
- Preserve existing prototype routes and views
- No Git push or deployment without approval

## Implementation state
PLANNING — no authentication migrations or account created.
