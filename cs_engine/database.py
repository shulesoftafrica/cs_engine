"""SQLite-backed persistence layer for the Customer Success Engine."""

from __future__ import annotations

import datetime
import sqlite3
from contextlib import contextmanager
from pathlib import Path
from typing import Generator, List, Optional

from .models import Alert, AlertSeverity, AlertType, ChurnRisk, Customer, EngagementEvent, Playbook


_DEFAULT_DB_PATH = Path.home() / ".cs_engine" / "cs_engine.db"


def _connect(db_path: Path) -> sqlite3.Connection:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL;")
    conn.execute("PRAGMA foreign_keys=ON;")
    return conn


@contextmanager
def _cursor(conn: sqlite3.Connection) -> Generator[sqlite3.Cursor, None, None]:
    cur = conn.cursor()
    try:
        yield cur
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        cur.close()


class Database:
    """Manages all persistence for the Customer Success Engine."""

    def __init__(self, db_path: Optional[Path] = None) -> None:
        self._path = db_path or _DEFAULT_DB_PATH
        self._conn = _connect(self._path)
        self._migrate()

    # ------------------------------------------------------------------
    # Schema
    # ------------------------------------------------------------------

    def _migrate(self) -> None:
        with _cursor(self._conn) as cur:
            cur.executescript(
                """
                CREATE TABLE IF NOT EXISTS customers (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    name            TEXT    NOT NULL,
                    email           TEXT    NOT NULL UNIQUE,
                    plan            TEXT    NOT NULL DEFAULT 'starter',
                    mrr             REAL    NOT NULL DEFAULT 0.0,
                    contract_start  TEXT    NOT NULL,
                    contract_end    TEXT    NOT NULL,
                    nps_score       INTEGER,
                    health_score    REAL    NOT NULL DEFAULT 0.0,
                    churn_risk      TEXT    NOT NULL DEFAULT 'low',
                    created_at      TEXT    NOT NULL,
                    updated_at      TEXT    NOT NULL
                );

                CREATE TABLE IF NOT EXISTS engagement_events (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    customer_id     INTEGER NOT NULL REFERENCES customers(id),
                    event_type      TEXT    NOT NULL,
                    value           REAL    NOT NULL DEFAULT 1.0,
                    occurred_at     TEXT    NOT NULL,
                    notes           TEXT    NOT NULL DEFAULT ''
                );

                CREATE TABLE IF NOT EXISTS alerts (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    customer_id     INTEGER NOT NULL REFERENCES customers(id),
                    alert_type      TEXT    NOT NULL,
                    severity        TEXT    NOT NULL,
                    message         TEXT    NOT NULL,
                    resolved        INTEGER NOT NULL DEFAULT 0,
                    created_at      TEXT    NOT NULL,
                    resolved_at     TEXT
                );

                CREATE TABLE IF NOT EXISTS playbooks (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    name                TEXT    NOT NULL,
                    trigger_churn_risk  TEXT,
                    trigger_alert_type  TEXT,
                    description         TEXT    NOT NULL DEFAULT '',
                    action_steps        TEXT    NOT NULL DEFAULT ''
                );
                """
            )

    def close(self) -> None:
        self._conn.close()

    # ------------------------------------------------------------------
    # Customers
    # ------------------------------------------------------------------

    def add_customer(self, customer: Customer) -> Customer:
        now = datetime.datetime.now(datetime.timezone.utc).isoformat()
        with _cursor(self._conn) as cur:
            cur.execute(
                """
                INSERT INTO customers
                    (name, email, plan, mrr, contract_start, contract_end,
                     nps_score, health_score, churn_risk, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                """,
                (
                    customer.name,
                    customer.email,
                    customer.plan,
                    customer.mrr,
                    customer.contract_start.isoformat(),
                    customer.contract_end.isoformat(),
                    customer.nps_score,
                    customer.health_score,
                    customer.churn_risk.value,
                    now,
                    now,
                ),
            )
            customer.id = cur.lastrowid  # type: ignore[assignment]
        return customer

    def update_customer(self, customer: Customer) -> None:
        now = datetime.datetime.now(datetime.timezone.utc).isoformat()
        with _cursor(self._conn) as cur:
            cur.execute(
                """
                UPDATE customers
                SET name=?, email=?, plan=?, mrr=?, contract_start=?, contract_end=?,
                    nps_score=?, health_score=?, churn_risk=?, updated_at=?
                WHERE id=?
                """,
                (
                    customer.name,
                    customer.email,
                    customer.plan,
                    customer.mrr,
                    customer.contract_start.isoformat(),
                    customer.contract_end.isoformat(),
                    customer.nps_score,
                    customer.health_score,
                    customer.churn_risk.value,
                    now,
                    customer.id,
                ),
            )

    def get_customer(self, customer_id: int) -> Optional[Customer]:
        with _cursor(self._conn) as cur:
            cur.execute("SELECT * FROM customers WHERE id=?", (customer_id,))
            row = cur.fetchone()
        return _row_to_customer(row) if row else None

    def get_customer_by_email(self, email: str) -> Optional[Customer]:
        with _cursor(self._conn) as cur:
            cur.execute("SELECT * FROM customers WHERE email=?", (email,))
            row = cur.fetchone()
        return _row_to_customer(row) if row else None

    def list_customers(self) -> List[Customer]:
        with _cursor(self._conn) as cur:
            cur.execute("SELECT * FROM customers ORDER BY name")
            rows = cur.fetchall()
        return [_row_to_customer(r) for r in rows]

    def delete_customer(self, customer_id: int) -> None:
        with _cursor(self._conn) as cur:
            cur.execute("DELETE FROM customers WHERE id=?", (customer_id,))

    # ------------------------------------------------------------------
    # Engagement Events
    # ------------------------------------------------------------------

    def add_event(self, event: EngagementEvent) -> EngagementEvent:
        with _cursor(self._conn) as cur:
            cur.execute(
                """
                INSERT INTO engagement_events
                    (customer_id, event_type, value, occurred_at, notes)
                VALUES (?,?,?,?,?)
                """,
                (
                    event.customer_id,
                    event.event_type,
                    event.value,
                    event.occurred_at.isoformat(),
                    event.notes,
                ),
            )
            event.id = cur.lastrowid  # type: ignore[assignment]
        return event

    def list_events(self, customer_id: int) -> List[EngagementEvent]:
        with _cursor(self._conn) as cur:
            cur.execute(
                "SELECT * FROM engagement_events WHERE customer_id=? ORDER BY occurred_at DESC",
                (customer_id,),
            )
            rows = cur.fetchall()
        return [_row_to_event(r) for r in rows]

    # ------------------------------------------------------------------
    # Alerts
    # ------------------------------------------------------------------

    def has_open_alert(self, customer_id: int, alert_type: AlertType) -> bool:
        """Return True if an unresolved alert of *alert_type* exists for *customer_id*."""
        with _cursor(self._conn) as cur:
            cur.execute(
                "SELECT 1 FROM alerts WHERE customer_id=? AND alert_type=? AND resolved=0 LIMIT 1",
                (customer_id, alert_type.value),
            )
            return cur.fetchone() is not None

    def add_alert(self, alert: Alert) -> Alert:
        with _cursor(self._conn) as cur:
            cur.execute(
                """
                INSERT INTO alerts
                    (customer_id, alert_type, severity, message, resolved, created_at, resolved_at)
                VALUES (?,?,?,?,?,?,?)
                """,
                (
                    alert.customer_id,
                    alert.alert_type.value,
                    alert.severity.value,
                    alert.message,
                    int(alert.resolved),
                    alert.created_at.isoformat(),
                    alert.resolved_at.isoformat() if alert.resolved_at else None,
                ),
            )
            alert.id = cur.lastrowid  # type: ignore[assignment]
        return alert

    def list_alerts(
        self,
        customer_id: Optional[int] = None,
        resolved: Optional[bool] = None,
    ) -> List[Alert]:
        query = "SELECT * FROM alerts WHERE 1=1"
        params: list = []
        if customer_id is not None:
            query += " AND customer_id=?"
            params.append(customer_id)
        if resolved is not None:
            query += " AND resolved=?"
            params.append(int(resolved))
        query += " ORDER BY created_at DESC"
        with _cursor(self._conn) as cur:
            cur.execute(query, params)
            rows = cur.fetchall()
        return [_row_to_alert(r) for r in rows]

    def resolve_alert(self, alert_id: int) -> None:
        now = datetime.datetime.now(datetime.timezone.utc).isoformat()
        with _cursor(self._conn) as cur:
            cur.execute(
                "UPDATE alerts SET resolved=1, resolved_at=? WHERE id=?",
                (now, alert_id),
            )

    # ------------------------------------------------------------------
    # Playbooks
    # ------------------------------------------------------------------

    def add_playbook(self, playbook: Playbook) -> Playbook:
        with _cursor(self._conn) as cur:
            cur.execute(
                """
                INSERT INTO playbooks
                    (name, trigger_churn_risk, trigger_alert_type, description, action_steps)
                VALUES (?,?,?,?,?)
                """,
                (
                    playbook.name,
                    playbook.trigger_churn_risk.value if playbook.trigger_churn_risk else None,
                    playbook.trigger_alert_type.value if playbook.trigger_alert_type else None,
                    playbook.description,
                    playbook.action_steps,
                ),
            )
            playbook.id = cur.lastrowid  # type: ignore[assignment]
        return playbook

    def list_playbooks(self) -> List[Playbook]:
        with _cursor(self._conn) as cur:
            cur.execute("SELECT * FROM playbooks ORDER BY name")
            rows = cur.fetchall()
        return [_row_to_playbook(r) for r in rows]

    def get_playbooks_for_customer(self, customer: Customer) -> List[Playbook]:
        with _cursor(self._conn) as cur:
            cur.execute(
                """
                SELECT * FROM playbooks
                WHERE trigger_churn_risk IS NULL OR trigger_churn_risk=?
                ORDER BY name
                """,
                (customer.churn_risk.value,),
            )
            rows = cur.fetchall()
        return [_row_to_playbook(r) for r in rows]


# ------------------------------------------------------------------
# Row → model helpers
# ------------------------------------------------------------------

def _row_to_customer(row: sqlite3.Row) -> Customer:
    return Customer(
        id=row["id"],
        name=row["name"],
        email=row["email"],
        plan=row["plan"],
        mrr=row["mrr"],
        contract_start=datetime.date.fromisoformat(row["contract_start"]),
        contract_end=datetime.date.fromisoformat(row["contract_end"]),
        nps_score=row["nps_score"],
        health_score=row["health_score"],
        churn_risk=ChurnRisk(row["churn_risk"]),
        created_at=datetime.datetime.fromisoformat(row["created_at"]),
        updated_at=datetime.datetime.fromisoformat(row["updated_at"]),
    )


def _row_to_event(row: sqlite3.Row) -> EngagementEvent:
    return EngagementEvent(
        id=row["id"],
        customer_id=row["customer_id"],
        event_type=row["event_type"],
        value=row["value"],
        occurred_at=datetime.datetime.fromisoformat(row["occurred_at"]),
        notes=row["notes"],
    )


def _row_to_alert(row: sqlite3.Row) -> Alert:
    return Alert(
        id=row["id"],
        customer_id=row["customer_id"],
        alert_type=AlertType(row["alert_type"]),
        severity=AlertSeverity(row["severity"]),
        message=row["message"],
        resolved=bool(row["resolved"]),
        created_at=datetime.datetime.fromisoformat(row["created_at"]),
        resolved_at=(
            datetime.datetime.fromisoformat(row["resolved_at"])
            if row["resolved_at"]
            else None
        ),
    )


def _row_to_playbook(row: sqlite3.Row) -> Playbook:
    return Playbook(
        id=row["id"],
        name=row["name"],
        trigger_churn_risk=(
            ChurnRisk(row["trigger_churn_risk"]) if row["trigger_churn_risk"] else None
        ),
        trigger_alert_type=(
            AlertType(row["trigger_alert_type"]) if row["trigger_alert_type"] else None
        ),
        description=row["description"],
        action_steps=row["action_steps"],
    )
