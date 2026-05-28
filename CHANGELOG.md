# Changelog
## 1.1.0 - 2026-05-17

### Changed
- Default API base URL is now https://api.nakopay.com/v1/ (branded primary). Supabase functions URL kept as fallback constant.

## 1.0.0 - 2026-05-02

### Added
- Initial release of NakoPay payment adapter for FOSSBilling.
- Hosted checkout redirect via NakoPay invoice API.
- HMAC-SHA256 webhook signature verification with 5-minute replay window.
- Sandbox/test mode support.
- Configurable API base URL (Supabase primary, api.nakopay.com reserved fallback).
- Status mapping: NakoPay invoice statuses to FOSSBilling payment statuses.
- Idempotent webhook handling (duplicate events ignored).
- Debug logging for troubleshooting.
