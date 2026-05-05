# cs_engine — Customer Success Engine

A lightweight, self-contained **Customer Success Engine** built in Python.
Track customer health, predict churn risk, trigger alerts, and recommend
playbooks — all from a single CLI backed by a local SQLite database.

---

## Features

| Capability | Details |
|---|---|
| **Health Score (0–100)** | Weighted blend of engagement activity, support-ticket load, NPS, and renewal proximity |
| **Churn Risk** | `LOW` (≥70) · `MEDIUM` (40–69) · `HIGH` (<40) |
| **Alerts** | `health_drop` · `renewal_approaching` · `ticket_spike` · `no_engagement` — with deduplication so you see each alert only once until resolved |
| **Playbooks** | Define recommended action plans per risk level; engine surfaces the right ones per customer |
| **CLI** | Full CRUD for customers, events, alerts, and playbooks |
| **Zero external deps** | Pure Python standard library + `pytest` for testing |

---

## Quick start

```bash
# Install (editable)
pip install -e .

# Add a customer
cs-engine customer add \
  --name "Acme Corp" \
  --email acme@example.com \
  --plan enterprise \
  --mrr 5000 \
  --contract-start 2025-01-01 \
  --contract-end 2026-06-30 \
  --nps 60

# Record engagement events
cs-engine event record --customer-id 1 --type login
cs-engine event record --customer-id 1 --type feature_use --value 3
cs-engine event record --customer-id 1 --type support_ticket --value 1

# Run the engine (refresh all health scores + generate alerts)
cs-engine run

# View customers
cs-engine customer list
cs-engine customer show 1

# View & resolve alerts
cs-engine alert list --open
cs-engine alert resolve 5

# Manage playbooks
cs-engine playbook add \
  --name "High-Risk Outreach" \
  --churn-risk high \
  --description "Immediate CSM engagement for at-risk accounts" \
  --steps "Email CSM\nSchedule executive call\nOffer concession"

cs-engine playbook list

# Use a custom database path
cs-engine --db /path/to/data.db run
```

---

## Health score formula

```
health = 0.35 × engagement_score
       + 0.25 × support_score
       + 0.20 × nps_score
       + 0.20 × renewal_score
```

| Component | Description |
|---|---|
| `engagement_score` | Logins / feature-use events in the last 30 days (0 → 0, ≥20 → 100) |
| `support_score` | Inverse of open tickets (0 tickets → 100, ≥5 tickets → 0) |
| `nps_score` | NPS mapped from −100…100 to 0…100 (unknown NPS → neutral 50) |
| `renewal_score` | Days until contract end (>90 days → 100, expired → 0) |

---

## Project structure

```
cs_engine/          # Python package
├── __init__.py
├── models.py       # Customer, EngagementEvent, Alert, Playbook dataclasses
├── health.py       # Health score calculation
├── alerts.py       # Alert generation rules
├── database.py     # SQLite persistence layer
├── engine.py       # Core orchestrator (CSEngine class)
└── cli.py          # Command-line interface

tests/
├── test_health.py
├── test_alerts.py
├── test_engine.py
└── test_cli.py
```

---

## Running tests

```bash
pip install pytest
pytest tests/ -v
```

---

## Default database location

`~/.cs_engine/cs_engine.db` — override with `cs-engine --db <path>`.
