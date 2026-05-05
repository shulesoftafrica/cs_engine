"""Integration tests for the Customer Success Engine."""

from __future__ import annotations

import datetime
import tempfile
from pathlib import Path

import pytest

from cs_engine.engine import CSEngine
from cs_engine.models import ChurnRisk, Customer, EngagementEvent, Playbook


@pytest.fixture()
def engine():
    with tempfile.TemporaryDirectory() as tmpdir:
        db_path = Path(tmpdir) / "test.db"
        eng = CSEngine(db_path=db_path)
        yield eng
        eng.close()


def _sample_customer(**kwargs) -> Customer:
    defaults = dict(
        id=0,
        name="Beta Ltd",
        email="beta@example.com",
        plan="growth",
        mrr=1000.0,
        contract_start=datetime.date.today() - datetime.timedelta(days=60),
        contract_end=datetime.date.today() + datetime.timedelta(days=300),
    )
    defaults.update(kwargs)
    return Customer(**defaults)


class TestCustomerCRUD:
    def test_add_and_get_customer(self, engine):
        c = engine.add_customer(_sample_customer())
        assert c.id > 0
        fetched = engine.get_customer(c.id)
        assert fetched is not None
        assert fetched.email == "beta@example.com"

    def test_list_customers(self, engine):
        engine.add_customer(_sample_customer(name="A", email="a@x.com"))
        engine.add_customer(_sample_customer(name="B", email="b@x.com"))
        customers = engine.list_customers()
        assert len(customers) == 2

    def test_delete_customer(self, engine):
        c = engine.add_customer(_sample_customer())
        engine.delete_customer(c.id)
        assert engine.get_customer(c.id) is None

    def test_initial_health_score_set(self, engine):
        c = engine.add_customer(_sample_customer())
        assert 0 <= c.health_score <= 100


class TestEngagementEvents:
    def test_record_event_returns_saved_event(self, engine):
        c = engine.add_customer(_sample_customer())
        event = EngagementEvent(
            id=0, customer_id=c.id, event_type="login", value=1.0,
            occurred_at=datetime.datetime.now(datetime.timezone.utc),
        )
        saved = engine.record_event(event)
        assert saved.id > 0

    def test_event_updates_health_score(self, engine):
        c = engine.add_customer(_sample_customer())
        initial_score = c.health_score
        for _ in range(20):
            engine.record_event(EngagementEvent(
                id=0, customer_id=c.id, event_type="login", value=1.0,
                occurred_at=datetime.datetime.now(datetime.timezone.utc),
            ))
        updated = engine.get_customer(c.id)
        assert updated.health_score >= initial_score

    def test_list_events_for_customer(self, engine):
        c = engine.add_customer(_sample_customer())
        for i in range(3):
            engine.record_event(EngagementEvent(
                id=0, customer_id=c.id, event_type="feature_use", value=float(i),
                occurred_at=datetime.datetime.now(datetime.timezone.utc),
            ))
        events = engine.list_events(c.id)
        assert len(events) == 3


class TestAlerts:
    def test_run_generates_no_alerts_for_healthy_customer(self, engine):
        c = engine.add_customer(_sample_customer(
            contract_end=datetime.date.today() + datetime.timedelta(days=300),
        ))
        for _ in range(20):
            engine.record_event(EngagementEvent(
                id=0, customer_id=c.id, event_type="login", value=1.0,
                occurred_at=datetime.datetime.now(datetime.timezone.utc),
            ))
        results = engine.run()
        _, alerts = results[0]
        # Healthy customer with good engagement shouldn't have critical alerts
        critical = [a for a in alerts if a.severity.value == "critical"]
        assert len(critical) == 0

    def test_alert_resolve(self, engine):
        c = engine.add_customer(_sample_customer(
            contract_end=datetime.date.today() + datetime.timedelta(days=10),
        ))
        engine.run()  # triggers renewal alert
        open_alerts = engine.list_alerts(customer_id=c.id, resolved=False)
        if open_alerts:
            engine.resolve_alert(open_alerts[0].id)
            resolved = engine.list_alerts(customer_id=c.id, resolved=True)
            assert len(resolved) >= 1


class TestPlaybooks:
    def test_add_and_list_playbooks(self, engine):
        pb = Playbook(
            id=0, name="Re-engagement", trigger_churn_risk=ChurnRisk.HIGH,
            trigger_alert_type=None,
            description="Reach out to customer",
            action_steps="Send email\nSchedule call",
        )
        saved = engine.add_playbook(pb)
        assert saved.id > 0
        playbooks = engine.list_playbooks()
        assert any(p.name == "Re-engagement" for p in playbooks)

    def test_recommend_playbooks_for_high_risk(self, engine):
        # Add a HIGH-risk playbook
        engine.add_playbook(Playbook(
            id=0, name="High Risk Response", trigger_churn_risk=ChurnRisk.HIGH,
            trigger_alert_type=None, description="Immediate outreach",
            action_steps="Call CSM",
        ))
        # Add customer who will be HIGH risk
        c = engine.add_customer(_sample_customer(
            nps_score=-80,
            contract_end=datetime.date.today() + datetime.timedelta(days=5),
        ))
        # Force high risk classification
        c.churn_risk = ChurnRisk.HIGH
        engine.db.update_customer(c)

        playbooks = engine.recommend_playbooks(c.id)
        assert any(p.name == "High Risk Response" for p in playbooks)


class TestRunEngine:
    def test_run_returns_results_for_all_customers(self, engine):
        engine.add_customer(_sample_customer(name="A", email="a@x.com"))
        engine.add_customer(_sample_customer(name="B", email="b@x.com"))
        results = engine.run()
        assert len(results) == 2

    def test_run_updates_health_scores(self, engine):
        engine.add_customer(_sample_customer())
        results = engine.run()
        for customer, _ in results:
            assert 0 <= customer.health_score <= 100
