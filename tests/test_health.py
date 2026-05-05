"""Tests for the health score calculation."""

from __future__ import annotations

import datetime

import pytest

from cs_engine.health import (
    calculate_health_score,
    classify_churn_risk,
    _engagement_score,
    _nps_score,
    _renewal_score,
    _support_score,
)
from cs_engine.models import ChurnRisk, Customer, EngagementEvent


def _make_customer(**kwargs) -> Customer:
    defaults = dict(
        id=1,
        name="Acme Corp",
        email="acme@example.com",
        plan="pro",
        mrr=500.0,
        contract_start=datetime.date.today() - datetime.timedelta(days=180),
        contract_end=datetime.date.today() + datetime.timedelta(days=180),
    )
    defaults.update(kwargs)
    return Customer(**defaults)


def _make_event(event_type: str, days_ago: int = 1, value: float = 1.0) -> EngagementEvent:
    return EngagementEvent(
        id=0,
        customer_id=1,
        event_type=event_type,
        value=value,
        occurred_at=datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days_ago),
    )


# ------------------------------------------------------------------
# Engagement score
# ------------------------------------------------------------------

class TestEngagementScore:
    def test_no_events_gives_zero(self):
        assert _engagement_score([]) == 0.0

    def test_twenty_or_more_events_gives_hundred(self):
        events = [_make_event("login") for _ in range(20)]
        assert _engagement_score(events) == 100.0

    def test_ten_events_gives_fifty(self):
        events = [_make_event("login") for _ in range(10)]
        assert _engagement_score(events) == 50.0

    def test_old_events_are_excluded(self):
        events = [_make_event("login", days_ago=60)]  # outside 30-day window
        assert _engagement_score(events, window_days=30) == 0.0

    def test_support_tickets_excluded_from_engagement(self):
        events = [_make_event("support_ticket") for _ in range(20)]
        assert _engagement_score(events) == 0.0


# ------------------------------------------------------------------
# Support score
# ------------------------------------------------------------------

class TestSupportScore:
    def test_no_tickets_gives_hundred(self):
        assert _support_score([]) == 100.0

    def test_five_or_more_tickets_gives_zero(self):
        events = [_make_event("support_ticket", value=1) for _ in range(5)]
        assert _support_score(events) == 0.0

    def test_partial_tickets(self):
        events = [_make_event("support_ticket", value=1) for _ in range(2)]
        score = _support_score(events)
        assert 0 < score < 100

    def test_old_tickets_excluded(self):
        events = [_make_event("support_ticket", days_ago=60) for _ in range(5)]
        assert _support_score(events, window_days=30) == 100.0


# ------------------------------------------------------------------
# NPS score
# ------------------------------------------------------------------

class TestNPSScore:
    def test_none_gives_fifty(self):
        c = _make_customer(nps_score=None)
        assert _nps_score(c) == 50.0

    def test_max_nps_gives_hundred(self):
        c = _make_customer(nps_score=100)
        assert _nps_score(c) == 100.0

    def test_min_nps_gives_zero(self):
        c = _make_customer(nps_score=-100)
        assert _nps_score(c) == 0.0

    def test_zero_nps_gives_fifty(self):
        c = _make_customer(nps_score=0)
        assert _nps_score(c) == 50.0


# ------------------------------------------------------------------
# Renewal score
# ------------------------------------------------------------------

class TestRenewalScore:
    def test_expired_gives_zero(self):
        c = _make_customer(contract_end=datetime.date.today() - datetime.timedelta(days=1))
        assert _renewal_score(c) == 0.0

    def test_ninety_plus_days_gives_hundred(self):
        c = _make_customer(contract_end=datetime.date.today() + datetime.timedelta(days=90))
        assert _renewal_score(c) == 100.0

    def test_sixty_days_gives_between_fifty_and_hundred(self):
        c = _make_customer(contract_end=datetime.date.today() + datetime.timedelta(days=60))
        score = _renewal_score(c)
        assert 50 <= score <= 100

    def test_fifteen_days_gives_between_zero_and_fifty(self):
        c = _make_customer(contract_end=datetime.date.today() + datetime.timedelta(days=15))
        score = _renewal_score(c)
        assert 0 < score < 50


# ------------------------------------------------------------------
# Overall health score
# ------------------------------------------------------------------

class TestCalculateHealthScore:
    def test_score_in_range(self):
        c = _make_customer()
        events = [_make_event("login") for _ in range(10)]
        score = calculate_health_score(c, events)
        assert 0 <= score <= 100

    def test_healthy_customer_scores_high(self):
        c = _make_customer(
            nps_score=80,
            contract_end=datetime.date.today() + datetime.timedelta(days=200),
        )
        events = [_make_event("login") for _ in range(20)]
        score = calculate_health_score(c, events)
        assert score >= 70, f"Expected high health score, got {score}"

    def test_unhealthy_customer_scores_low(self):
        c = _make_customer(
            nps_score=-80,
            contract_end=datetime.date.today() + datetime.timedelta(days=5),
        )
        events = [_make_event("support_ticket", value=1) for _ in range(5)]
        score = calculate_health_score(c, events)
        assert score < 40, f"Expected low health score, got {score}"


# ------------------------------------------------------------------
# Churn risk classification
# ------------------------------------------------------------------

class TestClassifyChurnRisk:
    def test_low(self):
        assert classify_churn_risk(85) == ChurnRisk.LOW

    def test_medium(self):
        assert classify_churn_risk(55) == ChurnRisk.MEDIUM

    def test_high(self):
        assert classify_churn_risk(20) == ChurnRisk.HIGH

    def test_boundary_70_is_low(self):
        assert classify_churn_risk(70) == ChurnRisk.LOW

    def test_boundary_40_is_medium(self):
        assert classify_churn_risk(40) == ChurnRisk.MEDIUM

    def test_boundary_39_is_high(self):
        assert classify_churn_risk(39) == ChurnRisk.HIGH
