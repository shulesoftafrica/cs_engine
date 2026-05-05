"""Tests for the command-line interface."""

from __future__ import annotations

import datetime
import tempfile
from pathlib import Path

import pytest

from cs_engine.cli import main


@pytest.fixture()
def db_path(tmp_path):
    return str(tmp_path / "test.db")


def run(*args, db_path):
    main(["--db", db_path] + list(args))


class TestCustomerCLI:
    def test_add_and_list(self, db_path, capsys):
        run(
            "customer", "add",
            "--name", "Acme Corp",
            "--email", "acme@test.com",
            "--plan", "pro",
            "--mrr", "500",
            "--contract-start", "2024-01-01",
            "--contract-end", "2026-12-31",
            db_path=db_path,
        )
        run("customer", "list", db_path=db_path)
        captured = capsys.readouterr()
        assert "Acme Corp" in captured.out

    def test_show(self, db_path, capsys):
        run(
            "customer", "add",
            "--name", "ShowCo",
            "--email", "show@test.com",
            "--contract-start", "2024-01-01",
            "--contract-end", "2026-12-31",
            db_path=db_path,
        )
        run("customer", "show", "1", db_path=db_path)
        captured = capsys.readouterr()
        assert "ShowCo" in captured.out


class TestEventCLI:
    def test_record_event(self, db_path, capsys):
        run(
            "customer", "add",
            "--name", "EventCo",
            "--email", "event@test.com",
            "--contract-start", "2024-01-01",
            "--contract-end", "2026-12-31",
            db_path=db_path,
        )
        run(
            "event", "record",
            "--customer-id", "1",
            "--type", "login",
            db_path=db_path,
        )
        captured = capsys.readouterr()
        assert "Event recorded" in captured.out


class TestAlertCLI:
    def test_list_alerts(self, db_path, capsys):
        run("alert", "list", db_path=db_path)
        captured = capsys.readouterr()
        assert "No alerts" in captured.out


class TestRunCLI:
    def test_run_with_no_customers(self, db_path, capsys):
        run("run", db_path=db_path)
        captured = capsys.readouterr()
        assert "No customers" in captured.out

    def test_run_with_customers(self, db_path, capsys):
        run(
            "customer", "add",
            "--name", "RunCo",
            "--email", "run@test.com",
            "--contract-start", "2024-01-01",
            "--contract-end", "2026-12-31",
            db_path=db_path,
        )
        run("run", db_path=db_path)
        captured = capsys.readouterr()
        assert "RunCo" in captured.out


class TestPlaybookCLI:
    def test_add_and_list_playbook(self, db_path, capsys):
        run(
            "playbook", "add",
            "--name", "Churn Prevention",
            "--churn-risk", "high",
            "--description", "Reach out immediately",
            "--steps", "Call CSM\nSend email",
            db_path=db_path,
        )
        run("playbook", "list", db_path=db_path)
        captured = capsys.readouterr()
        assert "Churn Prevention" in captured.out
