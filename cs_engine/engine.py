"""Core Customer Success Engine orchestrator."""

from __future__ import annotations

from pathlib import Path
from typing import List, Optional, Tuple

from .alerts import generate_alerts
from .database import Database
from .health import calculate_health_score, classify_churn_risk
from .models import Alert, Customer, EngagementEvent, Playbook


class CSEngine:
    """
    The Customer Success Engine.

    Orchestrates health-score computation, churn-risk classification,
    alert generation, and playbook recommendations for all customers.
    """

    def __init__(self, db_path: Optional[Path] = None) -> None:
        self.db = Database(db_path)

    def close(self) -> None:
        self.db.close()

    # ------------------------------------------------------------------
    # Customer management
    # ------------------------------------------------------------------

    def add_customer(self, customer: Customer) -> Customer:
        """Persist a new customer and compute their initial health score."""
        events = []  # no events yet
        customer.health_score = calculate_health_score(customer, events)
        customer.churn_risk = classify_churn_risk(customer.health_score)
        return self.db.add_customer(customer)

    def get_customer(self, customer_id: int) -> Optional[Customer]:
        return self.db.get_customer(customer_id)

    def list_customers(self) -> List[Customer]:
        return self.db.list_customers()

    def delete_customer(self, customer_id: int) -> None:
        self.db.delete_customer(customer_id)

    # ------------------------------------------------------------------
    # Engagement
    # ------------------------------------------------------------------

    def record_event(self, event: EngagementEvent) -> EngagementEvent:
        """Record an engagement event and refresh the customer's health score."""
        saved_event = self.db.add_event(event)
        self._refresh_customer(event.customer_id)
        return saved_event

    def list_events(self, customer_id: int) -> List[EngagementEvent]:
        return self.db.list_events(customer_id)

    # ------------------------------------------------------------------
    # Health & risk
    # ------------------------------------------------------------------

    def _refresh_customer(self, customer_id: int) -> Optional[Customer]:
        """Recompute health score and churn risk; persist; generate alerts."""
        customer = self.db.get_customer(customer_id)
        if customer is None:
            return None

        previous_score = customer.health_score
        events = self.db.list_events(customer_id)

        customer.health_score = calculate_health_score(customer, events)
        customer.churn_risk = classify_churn_risk(customer.health_score)
        self.db.update_customer(customer)

        new_alerts = generate_alerts(customer, events, previous_score)
        for alert in new_alerts:
            if not self.db.has_open_alert(customer.id, alert.alert_type):
                self.db.add_alert(alert)

        return customer

    def run(self) -> List[Tuple[Customer, List[Alert]]]:
        """
        Refresh all customers and return a list of (customer, new_alerts) tuples.

        Call this periodically (e.g. daily via cron) to keep scores current
        and surface new alerts.
        """
        results: List[Tuple[Customer, List[Alert]]] = []
        for customer in self.db.list_customers():
            previous_score = customer.health_score
            events = self.db.list_events(customer.id)

            customer.health_score = calculate_health_score(customer, events)
            customer.churn_risk = classify_churn_risk(customer.health_score)
            self.db.update_customer(customer)

            new_alerts = generate_alerts(customer, events, previous_score)
            saved_alerts: List[Alert] = []
            for alert in new_alerts:
                if not self.db.has_open_alert(customer.id, alert.alert_type):
                    self.db.add_alert(alert)
                    saved_alerts.append(alert)

            results.append((customer, saved_alerts))
        return results

    # ------------------------------------------------------------------
    # Alerts
    # ------------------------------------------------------------------

    def list_alerts(
        self,
        customer_id: Optional[int] = None,
        resolved: Optional[bool] = None,
    ) -> List[Alert]:
        return self.db.list_alerts(customer_id=customer_id, resolved=resolved)

    def resolve_alert(self, alert_id: int) -> None:
        self.db.resolve_alert(alert_id)

    # ------------------------------------------------------------------
    # Playbooks
    # ------------------------------------------------------------------

    def add_playbook(self, playbook: Playbook) -> Playbook:
        return self.db.add_playbook(playbook)

    def list_playbooks(self) -> List[Playbook]:
        return self.db.list_playbooks()

    def recommend_playbooks(self, customer_id: int) -> List[Playbook]:
        customer = self.db.get_customer(customer_id)
        if customer is None:
            return []
        return self.db.get_playbooks_for_customer(customer)
