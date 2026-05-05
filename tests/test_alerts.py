"""Tests for the alert generation logic."""

from __future__ import annotations

import datetime

import pytest

from cs_engine.alerts import (
    _check_health_drop,
    _check_no_engagement,
    _check_renewal_approaching,
    _check_ticket_spike,
    generate_alerts,
)
from cs_engine.models import AlertSeverity, AlertType, ChurnRisk, Customer, EngagementEvent


def _make_customer(**kwargs) -> Customer:
    defaults = dict(
        id=1,
        name="Test Co",
        email="test@example.com",
        plan="pro",
        mrr=300.0,
        contract_start=datetime.date.today() - datetime.timedelta(days=90),
        contract_end=datetime.date.today() + datetime.timedelta(days=180),
        health_score=75.0,
        churn_risk=ChurnRisk.LOW,
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


class TestCheckHealthDrop:
    def test_no_alert_when_score_improves(self):
        c = _make_customer(health_score=80)
        alerts = _check_health_drop(c, previous_score=70)
        assert alerts == []

    def test_no_alert_when_drop_is_small(self):
        c = _make_customer(health_score=70)
        alerts = _check_health_drop(c, previous_score=75, threshold=10)
        assert alerts == []

    def test_warning_alert_on_medium_drop(self):
        c = _make_customer(health_score=55)
        alerts = _check_health_drop(c, previous_score=70, threshold=10)
        assert len(alerts) == 1
        assert alerts[0].alert_type == AlertType.HEALTH_DROP
        assert alerts[0].severity == AlertSeverity.WARNING

    def test_critical_alert_when_score_drops_below_40(self):
        c = _make_customer(health_score=30)
        alerts = _check_health_drop(c, previous_score=60, threshold=10)
        assert len(alerts) == 1
        assert alerts[0].severity == AlertSeverity.CRITICAL


class TestCheckRenewalApproaching:
    def test_no_alert_far_future(self):
        c = _make_customer(contract_end=datetime.date.today() + datetime.timedelta(days=90))
        assert _check_renewal_approaching(c) == []

    def test_warning_when_45_days(self):
        c = _make_customer(contract_end=datetime.date.today() + datetime.timedelta(days=45))
        alerts = _check_renewal_approaching(c)
        assert len(alerts) == 1
        assert alerts[0].alert_type == AlertType.RENEWAL_APPROACHING
        assert alerts[0].severity == AlertSeverity.WARNING

    def test_critical_when_15_days(self):
        c = _make_customer(contract_end=datetime.date.today() + datetime.timedelta(days=15))
        alerts = _check_renewal_approaching(c)
        assert len(alerts) == 1
        assert alerts[0].severity == AlertSeverity.CRITICAL

    def test_no_alert_for_expired_contract(self):
        c = _make_customer(contract_end=datetime.date.today() - datetime.timedelta(days=5))
        assert _check_renewal_approaching(c) == []


class TestCheckTicketSpike:
    def test_no_alert_below_threshold(self):
        events = [_make_event("support_ticket") for _ in range(2)]
        c = _make_customer()
        assert _check_ticket_spike(events, c, spike_threshold=3) == []

    def test_alert_at_threshold(self):
        events = [_make_event("support_ticket") for _ in range(3)]
        c = _make_customer()
        alerts = _check_ticket_spike(events, c, spike_threshold=3)
        assert len(alerts) == 1
        assert alerts[0].alert_type == AlertType.TICKET_SPIKE

    def test_old_tickets_not_counted(self):
        events = [_make_event("support_ticket", days_ago=14) for _ in range(5)]
        c = _make_customer()
        assert _check_ticket_spike(events, c, window_days=7, spike_threshold=3) == []


class TestCheckNoEngagement:
    def test_no_alert_when_recent_activity(self):
        events = [_make_event("login", days_ago=5)]
        c = _make_customer()
        assert _check_no_engagement(events, c, silence_days=14) == []

    def test_alert_when_silent(self):
        events = []
        c = _make_customer()
        alerts = _check_no_engagement(events, c, silence_days=14)
        assert len(alerts) == 1
        assert alerts[0].alert_type == AlertType.NO_ENGAGEMENT

    def test_support_tickets_do_not_count_as_engagement(self):
        events = [_make_event("support_ticket", days_ago=5)]
        c = _make_customer()
        alerts = _check_no_engagement(events, c, silence_days=14)
        assert len(alerts) == 1


class TestGenerateAlerts:
    def test_returns_list(self):
        c = _make_customer()
        alerts = generate_alerts(c, [], previous_health_score=75.0)
        assert isinstance(alerts, list)

    def test_multiple_alert_types_can_fire(self):
        c = _make_customer(
            health_score=25.0,
            contract_end=datetime.date.today() + datetime.timedelta(days=15),
        )
        events = [_make_event("support_ticket") for _ in range(5)]
        alerts = generate_alerts(c, events, previous_health_score=75.0)
        types = {a.alert_type for a in alerts}
        assert AlertType.HEALTH_DROP in types
        assert AlertType.RENEWAL_APPROACHING in types
        assert AlertType.TICKET_SPIKE in types
