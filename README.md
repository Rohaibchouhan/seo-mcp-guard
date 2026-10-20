# SEO MCP Guard

> **WordPress MCP Server for Rank Math SEO Work ONLY.**  
> A dedicated, hardened Model Context Protocol (MCP) server that replaces general-purpose WordPress MCP plugins with a strictly whitelisted, safety-first SEO engine.

---

## Table of Contents

- [Overview](#overview)
- [Architecture & Security](#architecture--security)
- [Antigravity MCP Configuration](#antigravity-mcp-configuration)
  - [1. Streamable HTTP (Native)](#1-streamable-http-native)
  - [2. Stdio Bridge (`mcp-remote`)](#2-stdio-bridge-mcp-remote)
  - [3. Application Password Setup](#3-application-password-setup)
- [Safety Engine & Guarantees](#safety-engine--guarantees)
- [The Hard "NEVER" List](#the-hard-never-list)
- [Error Contract](#error-contract)
- [Whitelisted Tools Reference](#whitelisted-tools-reference)
- [Admin UI Features](#admin-ui-features)
- [Automated Tests](#automated-tests)
- [Installation & Packaging](#installation--packaging)

---

## Overview

General-purpose WordPress MCP plugins expose excessive attack surfaces: arbitrary file manipulation, option writes, user role modifications, and full post content overwrites.

**SEO MCP Guard** replaces them completely. It exposes an MCP server over JSON-RPC 2.0 at `/wp-json/seo-mcp/v1/mcp` restricted exclusively to SEO tasks with **Rank Math**. Every write passes through an atomic Safety Engine with pre-write snapshots, structure fingerprint assertions, automatic cache purging, and post-write health verification.

---

## Architecture & Security

```
                                      AI Agent (Antigravity / Claude)
                                                    │
                                                    ▼
                            POST /wp-json/seo-mcp/v1/mcp (JSON-RPC 2.0)
                                                    │
                     ┌──────────────────────────────┴──────────────────────────────┐
                     ▼                                                             ▼
         WordPress Application Password                                   Plugin Bearer Token
          (Basic base64(user:app_pass))                                (Bearer smcp_<sha256>)
                     └──────────────────────────────┬──────────────────────────────┘
                                                    │ (Requires edit_posts)
                                                    ▼
                                      Kill Switch & Rate Limiter
                                                    │
                                                    ▼
                                          Tool Dispatch Whitelist
                                                    │
                                                    ▼
                                        Safety Engine Pipeline
                                  ┌───────────────────────────────────┐
                                  │ 1. Idempotency Check              │
                                  │ 2. Batch-Halt Check               │
                                  │ 3. Per-Post Lock (transient)      │
                                  │ 4. Pre-Write Snapshot (DB Table)  │
                                  │ 5. Dry-Run Diff Preview           │
                                  │ 6. Rank Math Meta Write           │
                                  │ 7. Multi-Plugin Cache Purge       │
                                  │ 8. Health Check Verification      │
                                  │    ↳ Fail? Auto-Rollback & Halt   │
                                  └───────────────────────────────────┘
```

---

## Antigravity MCP Configuration

### 1. Streamable HTTP (Native)

For MCP clients that support direct HTTP transport (including Antigravity), add this to your MCP configuration (`mcp_config.json`):

```json
{
  "mcpServers": {
    "seo-mcp-guard": {
      "url": "https://your-domain.com/wp-json/seo-mcp/v1/mcp",
      "headers": {
        "Authorization": "Bearer smcp_YOUR_GENERATED_TOKEN_HERE"
      }
    }
  }
}
```

### 2. Stdio Bridge (`mcp-remote`)

If your client environment requires `stdio` transport, use the `mcp-remote` bridge:

```json
{
  "mcpServers": {
    "seo-mcp-guard": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-domain.com/wp-json/seo-mcp/v1/mcp",
        "--header",
        "Authorization: Bearer smcp_YOUR_GENERATED_TOKEN_HERE"
      ]
    }
  }
}
```

### 3. Application Password Setup

Alternatively, authenticate via WordPress core Application Passwords:

```json
{
  "mcpServers": {
    "seo-mcp-guard": {
      "url": "https://your-domain.com/wp-json/seo-mcp/v1/mcp",
      "headers": {
        "Authorization": "Basic dXNlcm5hbWU6YXBwbGljYXRpb25fcGFzc3dvcmQ="
      }
    }
  }
}
```

---

## Safety Engine & Guarantees

Every write tool passes through the multi-stage Safety Engine:

1. **Pre-Write Snapshots**: All old values are written to `{$wpdb->prefix}seo_mcp_guard_changes` (`change_id`, `batch_id`, `post_id`, `field`, `old_value`, `new_value`, `user_id`, `created_at`) BEFORE modifying any data.
2. **Dry Run by Default**: All write tools default to `dry_run: true`. Setting `dry_run: true` returns an exact before/after diff preview without touching the database. Changes are applied only when explicitly called with `dry_run: false`.
3. **Strict Schema Validation**:
   - `rank_math_title`: ≤ 60 characters.
   - `rank_math_description`: ≤ 160 characters.
   - `rank_math_focus_keyword`: Single phrase only (no pipes `|`).
   - `rank_math_canonical_url`: Valid absolute URL on the same domain.
   - `rank_math_robots`: Whitelisted directives only (`index`, `noindex`, `follow`, `nofollow`, `noarchive`, `nosnippet`, `noimageindex`, `none`). Contradictory pairs (`index` + `noindex`) are rejected.
4. **Idempotency**: Every call computes a deterministic idempotency key. Repeating the identical call with the same idempotency key makes no changes and returns the cached result.
5. **Concurrency Locks**: Per-post transient locks (`seo_mcp_guard_lock_{post_id}`) prevent concurrent write collisions.
6. **Post-Write Health Verification & Auto-Rollback**: After writing, caches are purged and the URL is verified via HTTP GET:
   - Must return HTTP 200.
   - Must not introduce accidental `noindex` headers.
   - If verification fails: **Automatic rollback** immediately restores previous values, the batch is marked **halted**, and further writes in that batch are blocked.
7. **Cache Invalidation**: Automatically clears caches across WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Autoptimize, and core object caches.
8. **Staging vs. Production**: If `WP_ENVIRONMENT_TYPE === 'production'`, writes are blocked unless the **Allow Production Writes** setting is explicitly checked in wp-admin.

---

## The Hard "NEVER" List

SEO MCP Guard structurally disallows all destructive actions:

- ❌ **No arbitrary PHP / JS / SQL / shell execution**, no `eval()`, no system commands.
- ❌ **No file read or write**, no theme/plugin file editing.
- ❌ **No `wp_options` modifications** outside plugin settings.
- ❌ **No user or role creation/elevation**.
- ❌ **No plugin/theme installation or activation**.
- ❌ **No post deletion or status tampering** (cannot publish, draft, or trash).
- ❌ **No full post_content replacement** (only targeted text changes with structure checks).
- ❌ **No CSS / script injection**.
- ❌ **Refuse page builder pages**: Any content-editing attempt on pages built with Elementor, Divi, WPBakery, Beaver Builder, Bricks, or Oxygen is immediately rejected with:  
  `"Page-builder page: SEO fields only."`

---

## Error Contract

Every refusal strictly follows the required JSON contract:

```json
{
  "ok": false,
  "code": "NOT_ALLOWED|VALIDATION|LOCKED|RATE_LIMIT|HEALTH_FAILED",
  "message": "Human-readable explanation of why the action was refused.",
  "instruction": "STOP. Do not try another method. Report this to the user."
}
```

---

## Whitelisted Tools Reference

### 1. `list_content`
List WordPress posts/pages with their current Rank Math SEO fields.
- `post_type` *(string, required)*: e.g. `'post'`, `'page'`.
- `page` *(int, default: 1)*
- `per_page` *(int, default: 20, max: 100)*
- `search` *(string, optional)*

### 2. `get_seo`
Retrieve all Rank Math fields, SEO score, word count, headings outline (`h1`-`h6`), and list of images missing `alt` text.
- `post_id` *(int, required)*

### 3. `update_seo`
Update Rank Math SEO meta fields. Whitelist only: `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`, `rank_math_canonical_url`, `rank_math_robots`, `rank_math_facebook_title`, `rank_math_facebook_description`, `rank_math_twitter_title`, `rank_math_twitter_description`, `rank_math_rich_snippet`.
- `post_id` *(int, required)*
- `fields` *(object, required)*: SEO fields to update.
- `dry_run` *(bool, default: true)*

### 4. `update_image_alt`
Update the `alt` text of an attachment image (max 125 chars).
- `attachment_id` *(int, required)*
- `alt` *(string, required)*
- `dry_run` *(bool, default: true)*

### 5. `update_slug`
Change post slug and automatically generate a Rank Math 301 redirect from the old URL. Refuses if it would collide with an existing slug or create a loop.
- `post_id` *(int, required)*
- `new_slug` *(string, required)*
- `dry_run` *(bool, default: true)*

### 6. `create_redirect`, `list_redirects`, `delete_redirect`
Manage Rank Math redirects with loop and chain detection.
- `create_redirect`: `source_url`, `target_url`, `redirect_type` (301, 302, 307), `dry_run`
- `list_redirects`: `page`, `per_page`, `search`
- `delete_redirect`: `redirect_id`, `dry_run`

### 7. `add_internal_link`
Wraps ONE existing plain-text occurrence in an `<a>` tag using DOM parsing. Refuses if text is inside a heading, existing link, shortcode, or block comment. Maximum 1 link per call. Compares structure fingerprint before saving (exactly one new `<a>` tag allowed).
- `post_id` *(int, required)*
- `anchor_text` *(string, required)*
- `target_url` *(string, required)*
- `dry_run` *(bool, default: true)*

### 8. `edit_text_block`
Text-only replacement inside a single Gutenberg block. Compares the structure fingerprint (tag counts, shortcodes list, block comment markers, CSS classes, href list) before and after. If any markup or class changes, refuses to save.
- `post_id` *(int, required)*
- `block_index` *(int, required)*: 0-based block index.
- `old_text` *(string, required)*
- `new_text` *(string, required)*
- `dry_run` *(bool, default: true)*

### 9. `audit_page`
Comprehensive read-only SEO audit: title/description length, keyword in headings/body/meta, single H1 count, missing alt text, canonical, noindex detection, broken internal links check, schema presence. Includes `"needs_developer"` items that cannot be fixed automatically.
- `post_id` *(int, required)*

### 10. `list_changes`, `rollback`, `rollback_batch`
Audit trail and rollback engine:
- `list_changes`: `limit` (default: 50), `post_id` (optional).
- `rollback`: `change_id` (UUID), `dry_run`. Restores old values.
- `rollback_batch`: `batch_id` (UUID), `dry_run`. Restores all changes in the batch in reverse chronological order.

### 11. `health_check`
Fetches the public URL of a post and verifies HTTP 200, indexability, title presence, and meta tags.
- `post_id` *(int, required)*

---

## Admin UI Features

Under **SEO MCP Guard** in WordPress admin:

1. **Settings Page**:
   - Master Kill Switch (instantly disables API access).
   - Read-Only Mode toggle.
   - Dry-Run-by-Default toggle.
   - Allow Production Writes toggle.
   - Post Type allowlist (checkboxes for all public post types).
   - Post ID allowlist (optional restriction).
   - Rate Limit (1–1000 requests/minute).
   - Max Batch Size limit.
   - Clean data on uninstall toggle.

2. **Audit Log Screen**:
   - Full history of all MCP operations.
   - Filter by post ID, status (`applied`, `rolled_back`, `dry_run`), date range, or batch ID.
   - One-click AJAX rollback per row.
   - Batch rollback.
   - CSV export with one click.

3. **Token Manager**:
   - Generate secure hashed bearer tokens.
   - Plaintext token displayed only once in a secure copy box.
   - Token revocation with instant confirmation.
   - Connection snippets pre-filled with your domain and endpoint.

---

## Automated Tests

Run the test suite via CLI in any PHP 8.0+ environment:

```bash
php tests/run-tests.php
```

All 6 test suites verify:
- ✅ **Whitelist Enforcement**: Only 10 allowed Rank Math fields; injection attempts rejected with `NOT_ALLOWED`.
- ✅ **Structure Fingerprint Check**: `EditTextBlock` and `AddInternalLink` structural assertions.
- ✅ **Rollback Engine**: Single field and multi-field batch rollback in reverse order.
- ✅ **Loop & Collision Detection**: Direct loop, chained circular redirects, and slug collision detection.
- ✅ **Idempotency**: Repeated calls return cached state without duplicate database mutations.
- ✅ **10x Consecutive Stability**: Running each write tool 10 times consecutively produces identical, stable outcomes.

---

## Installation & Packaging

1. Zip the plugin folder:
   ```bash
   Compress-Archive -Path seo-mcp-guard -DestinationPath seo-mcp-guard.zip
   ```
2. Upload `seo-mcp-guard.zip` in WordPress (**Plugins > Add New > Upload Plugin**).
3. Activate the plugin.
4. Navigate to **SEO MCP Guard > Token Manager** to generate your bearer token.
5. Add the MCP server to your Antigravity configuration.
