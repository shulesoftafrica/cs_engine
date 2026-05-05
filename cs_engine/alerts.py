"""Alert generation logic for the Customer Success Engine."""

from __future__ import annotations

import datetime
from typing import List

from .models import Alert, AlertSeverity, AlertType, Customer, EngagementEvent


def _check_health_drop(
    customer: Customer,
    previous_score: float,
    threshold: float = 10.0,
) -> List[Alert]:
    """Generate an alert when health score drops by more than *threshold* points."""
    alerts: List[Alert] = []
    drop = previous_score - customer.health_score
    if drop >= threshold:
        severity = (
            AlertSeverity.CRITICAL
            if customer.health_score < 40
            else AlertSeverity.WARNING
        )
        alerts.append(
            Alert(
                id=0,
                customer_id=customer.id,
                alert_type=AlertType.HEALTH_DROP,
                severity=severity,
                message=(
                    f"Health score dropped {drop:.1f} points "
                    f"(from {previous_score:.1f} to {customer.health_score:.1f})."
                ),
            )
        )
    return alerts


def _check_renewal_approaching(customer: Customer) -> List[Alert]:
    """Generate an alert when renewal date is within 60 days."""
    alerts: List[Alert] = []
    days = customer.days_until_renewal
    if 0 <= days <= 60:
        severity = AlertSeverity.CRITICAL if days <= 30 else AlertSeverity.WARNING
        alerts.append(
            Alert(
                id=0,
                customer_id=customer.id,
                alert_type=AlertType.RENEWAL_APPROACHING,
                severity=severity,
                message=(
                    f"Contract renewal is in {days} day(s) "
                    f"(expires {customer.contract_end})."
                ),
            )
        )
    return alerts


def _check_ticket_spike(
    events: List[EngagementEvent],
    customer: Customer,
    window_days: int = 7,
    spike_threshold: int = 3,
) -> List[Alert]:
    """Generate an alert when support tickets exceed *spike_threshold* in *window_days*."""
    alerts: List[Alert] = []
    cutoff = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=window_days)
    tickets = [
        e for e in events
        if e.event_type == "support_ticket" and e.occurred_at >= cutoff
    ]
    count = int(sum(e.value for e in tickets))
    if count >= spike_threshold:
        alerts.append(
            Alert(
                id=0,
                customer_id=customer.id,
                alert_type=AlertType.TICKET_SPIKE,
                severity=AlertSeverity.WARNING,
                message=(
                    f"{count} support ticket(s) in the last {window_days} day(s)."
                ),
            )
        )
    return alerts


def _check_no_engagement(
    events: List[EngagementEvent],
    customer: Customer,
    silence_days: int = 14,
) -> List[Alert]:
    """Generate an alert when there has been no engagement for *silence_days*."""
    alerts: List[Alert] = []
    cutoff = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=silence_days)
    recent = [
        e for e in events
        if e.event_type not in ("support_ticket",) and e.occurred_at >= cutoff
    ]
    if not recent:
        alerts.append(
            Alert(
                id=0,
                customer_id=customer.id,
                alert_type=AlertType.NO_ENGAGEMENT,
                severity=AlertSeverity.WARNING,
                message=(
                    f"No engagement events recorded in the last {silence_days} day(s)."
                ),
            )
        )
    return alerts


def generate_alerts(
    customer: Customer,
    events: List[EngagementEvent],
    previous_health_score: float = 0.0,
) -> List[Alert]:
    """
    Run all alert checks for *customer* and return a list of new alerts.

    Parameters
    ----------
    customer:             The customer record (with updated health_score).
    events:               All engagement events for this customer.
    previous_health_score: The health score before the latest recalculation.
    """
    alerts: List[Alert] = []
    alerts.extend(_check_health_drop(customer, previous_health_score))
    alerts.extend(_check_renewal_approaching(customer))
    alerts.extend(_check_ticket_spike(events, customer))
    alerts.extend(_check_no_engagement(events, customer))
    return alerts
