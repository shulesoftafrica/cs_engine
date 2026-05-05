"""Health score calculation for the Customer Success Engine."""

from __future__ import annotations

import datetime
from typing import List

from .models import Customer, EngagementEvent, ChurnRisk


# Weight breakdown for health score (must sum to 1.0)
WEIGHT_ENGAGEMENT = 0.35
WEIGHT_SUPPORT = 0.25
WEIGHT_NPS = 0.20
WEIGHT_RENEWAL = 0.20


def _engagement_score(events: List[EngagementEvent], window_days: int = 30) -> float:
    """
    Score 0–100 based on number of engagement events in the last *window_days*.

    Scoring curve:
      0 events  → 0
      ≥20 events → 100  (linear interpolation in between)
    """
    cutoff = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=window_days)
    recent = [
        e for e in events
        if e.event_type not in ("support_ticket",) and e.occurred_at >= cutoff
    ]
    count = len(recent)
    return min(count / 20.0, 1.0) * 100.0


def _support_score(events: List[EngagementEvent], window_days: int = 30) -> float:
    """
    Score 0–100 based on open/recent support tickets. More tickets = lower score.

    Scoring curve:
      0 tickets → 100
      ≥5 tickets →   0  (linear interpolation in between)
    """
    cutoff = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=window_days)
    tickets = [
        e for e in events
        if e.event_type == "support_ticket" and e.occurred_at >= cutoff
    ]
    count = sum(e.value for e in tickets)
    return max(1.0 - count / 5.0, 0.0) * 100.0


def _nps_score(customer: Customer) -> float:
    """Convert NPS (-100 to 100) to a 0–100 health component."""
    if customer.nps_score is None:
        return 50.0  # neutral when unknown
    return (customer.nps_score + 100) / 2.0


def _renewal_score(customer: Customer) -> float:
    """
    Score 0–100 based on days until contract renewal.

    >90 days  → 100 (plenty of time)
    30–90 days → linear 50–100
    0–30 days  → linear 0–50
    <0 days    → 0 (expired)
    """
    days = customer.days_until_renewal
    if days < 0:
        return 0.0
    if days >= 90:
        return 100.0
    if days >= 30:
        return 50.0 + (days - 30) / 60.0 * 50.0
    return days / 30.0 * 50.0


def calculate_health_score(
    customer: Customer,
    events: List[EngagementEvent],
    window_days: int = 30,
) -> float:
    """
    Compute a weighted health score (0–100) for *customer*.

    Parameters
    ----------
    customer:    The customer record.
    events:      All engagement events for this customer.
    window_days: Look-back window for engagement / support metrics.

    Returns
    -------
    float: Health score between 0 and 100 (inclusive).
    """
    eng = _engagement_score(events, window_days)
    sup = _support_score(events, window_days)
    nps = _nps_score(customer)
    ren = _renewal_score(customer)

    score = (
        WEIGHT_ENGAGEMENT * eng
        + WEIGHT_SUPPORT * sup
        + WEIGHT_NPS * nps
        + WEIGHT_RENEWAL * ren
    )
    return round(min(max(score, 0.0), 100.0), 2)


def classify_churn_risk(health_score: float) -> ChurnRisk:
    """Map a health score to a churn-risk category."""
    if health_score >= 70:
        return ChurnRisk.LOW
    if health_score >= 40:
        return ChurnRisk.MEDIUM
    return ChurnRisk.HIGH
