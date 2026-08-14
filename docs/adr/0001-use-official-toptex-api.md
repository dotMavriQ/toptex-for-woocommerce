# Replace the public-index integration with the official TopTex v3 API

The shipped v1.0.0 plugin read TopTex's public search index. We replaced that
with the official TopTex API (`https://api.toptex.io`, an AWS API Gateway).
The API is authoritative: it carries live dealer pricing and stock, exposes a
`usage_right` licensing flag, and returns the complete catalog (2,922 styles ×
colors × sizes) in a single paginated listing — none of which the public index
could provide.

This is hard to reverse (the mapping, auth, and pagination are all
API-specific) and surprising without context (a future reader might assume the
public index is still the source). Two real alternatives existed: keep reading
the public index (no auth, but no pricing/stock and no license semantics) vs.
use the official API (OIDC auth, richer data, but requires credentials). We
chose the official API because the requirement is to import "all or parts of
whatever one can fetch from a particular API that one has" — the API, not an
intermediary index, is the source of truth.
