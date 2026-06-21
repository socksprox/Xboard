# Plan action visibility (clients)

Guidelines for **which subscription actions to show** in user-facing clients (shadowfly-app, shadowfly-theme). Clients resolve buttons from a single `GET /api/v1/user/getSubscribe` load plus local rules. They do **not** call `GET /api/v1/user/order/options` to decide what to display.

Checkout still uses `POST /api/v1/user/order/save` (with optional `restart_cycle`).

## Goals

1. Show every action that has a **distinct, justified** user intent.
2. Hide an action when another available action is **strictly better** for the same intent (dominated option).
3. Keep **extend** (time-only) separate from **reset / restart** (data-now).

## Data sources

| Field | Source | Used for |
|-------|--------|----------|
| `plan`, `expired_at`, `u`, `d`, `transfer_enable`, `reset_day` | `getSubscribe` | Depletion, expiry, free-reset timing |
| `purchase_summary.{extend,restart,reset}_available` | `getSubscribe` (optional) | Backend eligibility flags |
| `plan.renew`, reset traffic price, month price | `getSubscribe.plan` | Fallback when summary missing; price comparison |

**Availability fallback** (when `purchase_summary` is absent):

- `reset` — plan has reset price + usage ≥ 80% (or `traffic_reset_usage=` tag on plan)
- `restart` — `plan.renew` and data depleted (and `restart_cycle_enable` on server)
- `extend` — `plan.renew` and subscription period still active

When `purchase_summary` is present, use its booleans (same semantics as `PlanService` / `PurchaseOptionsService`).

## Action definitions

| Action | Checkout `intent` | Data now? | Expiry | User intent |
|--------|-------------------|-----------|--------|-------------|
| **Browse plans** | — (shop route) | — | — | Expired; pick a new plan |
| **Renew same plan** | `extend` | — | New purchase after expiry | Re-subscribe to current plan |
| **Reset data** | `reset` | Yes | Unchanged | Need VPN now; keep remaining paid time |
| **New period** | `restart` (`restart_cycle`) | Yes | New period from today; forfeits remaining days | Need VPN now; OK losing prepaid days for a fresh month |
| **Extend** | `extend` | No | Adds time only | Prepay / extend early; does not restore data this month |

**Free reset** (wait) is not a button — show in the subscription status line via `reset_day` / `next_reset_at` when the calendar reset is before expiry.

## Where actions appear

| Surface | Implementation |
|---------|----------------|
| **shadowfly-app** — My Plan | `lib/subscription/plan_actions.dart` → `resolvePlanActions()` |
| **shadowfly-theme** — Dashboard / Mysubs | `src/views/stage/utils/planActions.js` → `PlanActionsPanel.vue` |
| **shadowfly-theme** — Webview gate CTA | `getPrimaryActionRoute()` — first resolved action |
| **Checkout** | `BuysubsOrder.vue` / `purchase_sheet.dart` — opened with `?intent=` from a button; does not drive visibility |

Legacy route `/stage/buysubs/manage` redirects to Mysubs (manage sheet removed).

---

## Scenario matrix

### Expired period subscription (`expired_at` in the past)

| Browse plans | Renew same plan |
|:------------:|:---------------:|
| ✓ | ✓ if `plan.renew` |

No reset / restart / extend (subscription not active on backend).

### One-time plan (`expired_at` is null)

| Depleted | Reset data |
|:--------:|:----------:|
| Yes, eligible | ✓ |
| No | — |

No extend or restart on one-time plans.

### Active period — not depleted

| Extend |
|:------:|
| ✓ if `plan.renew` / `extend_available` |

Status line may show days until free reset (`reset_day`) when that date is before expiry. No separate wait hint under buttons (avoid duplicating status copy).

### Active period — depleted

Evaluate three **independent** intents:

1. **Data now** — reset and/or restart (mutually exclusive when one dominates the other; see below)
2. **Time only** — extend if renewable (always justified when eligible, even when depleted)

| Condition | Reset data | New period | Extend |
|-----------|:----------:|:----------:|:------:|
| Only reset available | ✓ | | ✓ if renewable |
| Only restart available | | ✓ | ✓ if renewable |
| Reset **cheaper** than 1 month | ✓ | ✗ dominated | ✓ if renewable |
| Reset **≥ month price**, **> 7 days** left on sub | ✓ | ✗ dominated | ✓ if renewable |
| Reset **≥ month price**, **≤ 7 days** left on sub | ✗ dominated | ✓ | ✓ if renewable |
| Both available, prices unknown | ✓ | ✓ | ✓ if renewable |

**Example (common):** 150/150 GB used, 29 days until expiry, free reset in 11 days, reset price = month price → **Reset data** + **Extend** (not new period).

---

## Reset vs new period (dominance rules)

Compare traffic-reset price to **monthly** plan price (legacy `reset_price` / `month_price` cents, or `prices.reset_traffic` / `prices.monthly`).

| Tier | Show reset? | Show restart? |
|------|:-----------:|:-------------:|
| Reset cheaper than month | ✓ | ✗ (worse value) |
| Reset ≥ month price, **> 7** days remaining | ✓ | ✗ (forfeits prepaid time for little gain) |
| Reset ≥ month price, **≤ 7** days remaining | ✗ | ✓ (fresh month beats keeping a few days) |
| Prices unknown | ✓ | ✓ (conservative: show both) |

Constant `7` matches `PurchaseOptionsService` short-remaining threshold on the backend.

**Do not hide paid options** only because a free reset is soon — the status line already says when data returns. Users who need VPN immediately still need a paid path.

**Extend is never hidden** by reset/restart dominance — prepaying time without needing data this month is a separate decision.

---

## Checkout notes

- Opening checkout with `intent=reset|restart|extend` may **skip** `GET /order/options` for the intent picker (intent already chosen).
- Depleted **extend** checkout should warn: extends expiry only, does not restore data this month.
- Depleted **restart** checkout should warn about forfeited days (preview from options when loaded, or generic copy).

---

## Implementation map

| Layer | Path |
|-------|------|
| Backend summary | `app/Services/PurchaseOptionsService.php` → `buildPurchaseSummary()` |
| Backend options (checkout previews) | `app/Services/PurchaseOptionsService.php` → `buildForUser()` |
| Theme resolver | `shadowfly-theme/src/views/stage/utils/planActions.js` |
| Theme justification | `shadowfly-theme/src/views/stage/utils/plan.js` — `isResetTrafficJustified`, `isRestartCycleJustified`, `isExtendJustified` |
| App resolver | `shadowfly-app/lib/subscription/plan_actions.dart` |

**Keep theme and app logic in sync** when changing rules.

---

## Changing these rules

1. Update justification helpers in **both** `plan.js` and `plan_actions.dart`.
2. Update this document and the scenario tables.
3. Prefer aligning dominance thresholds with `PurchaseOptionsService` where possible.
4. Manual checks:
   - Expired → browse + renew
   - Active 50% used → extend only
   - Active 100% used, 29d left, reset = month price → reset + extend, no restart
   - Active 100% used, 5d left, reset = month price → restart + extend, no reset
