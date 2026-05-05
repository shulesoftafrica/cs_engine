"""Command-line interface for the Customer Success Engine."""

from __future__ import annotations

import argparse
import datetime
import sys
from pathlib import Path
from typing import Optional

from .engine import CSEngine
from .models import ChurnRisk, Customer, EngagementEvent, Playbook


# ANSI colour helpers (gracefully disabled if not supported)
def _colorize(code: str, text: str) -> str:
    if not sys.stdout.isatty():
        return text
    return f"\033[{code}m{text}\033[0m"


def RED(text: str) -> str:
    return _colorize("31", text)


def YELLOW(text: str) -> str:
    return _colorize("33", text)


def GREEN(text: str) -> str:
    return _colorize("32", text)


def BOLD(text: str) -> str:
    return _colorize("1", text)


def CYAN(text: str) -> str:
    return _colorize("36", text)


def _risk_colour(risk: ChurnRisk) -> str:
    if risk == ChurnRisk.HIGH:
        return RED(risk.value.upper())
    if risk == ChurnRisk.MEDIUM:
        return YELLOW(risk.value.upper())
    return GREEN(risk.value.upper())


def _score_colour(score: float) -> str:
    text = f"{score:.1f}"
    if score >= 70:
        return GREEN(text)
    if score >= 40:
        return YELLOW(text)
    return RED(text)


# ------------------------------------------------------------------
# Sub-command handlers
# ------------------------------------------------------------------

def cmd_customer_add(engine: CSEngine, args: argparse.Namespace) -> None:
    customer = Customer(
        id=0,
        name=args.name,
        email=args.email,
        plan=args.plan,
        mrr=args.mrr,
        contract_start=datetime.date.fromisoformat(args.contract_start),
        contract_end=datetime.date.fromisoformat(args.contract_end),
        nps_score=args.nps,
    )
    saved = engine.add_customer(customer)
    print(
        f"Customer added: [{saved.id}] {saved.name} <{saved.email}> | "
        f"Health: {_score_colour(saved.health_score)} | "
        f"Risk: {_risk_colour(saved.churn_risk)}"
    )


def cmd_customer_list(engine: CSEngine, _args: argparse.Namespace) -> None:
    customers = engine.list_customers()
    if not customers:
        print("No customers found.")
        return
    print(
        f"{'ID':<4} {'Name':<25} {'Plan':<12} {'MRR':>8}  "
        f"{'Health':>7}  {'Risk':<8}  {'Renewal':<12}"
    )
    print("-" * 85)
    for c in customers:
        print(
            f"{c.id:<4} {c.name:<25} {c.plan:<12} ${c.mrr:>8.2f}  "
            f"{_score_colour(c.health_score):>7}  {_risk_colour(c.churn_risk):<8}  "
            f"{str(c.contract_end):<12}"
        )


def cmd_customer_show(engine: CSEngine, args: argparse.Namespace) -> None:
    customer = engine.get_customer(args.id)
    if customer is None:
        print(f"Customer #{args.id} not found.")
        sys.exit(1)
    print(BOLD(f"\n=== {customer.name} ==="))
    print(f"  Email      : {customer.email}")
    print(f"  Plan       : {customer.plan}")
    print(f"  MRR        : ${customer.mrr:.2f}")
    print(f"  Contract   : {customer.contract_start} → {customer.contract_end}")
    print(f"  Renewal in : {customer.days_until_renewal} day(s)")
    print(f"  NPS        : {customer.nps_score if customer.nps_score is not None else 'N/A'}")
    print(f"  Health     : {_score_colour(customer.health_score)}/100")
    print(f"  Churn Risk : {_risk_colour(customer.churn_risk)}\n")

    events = engine.list_events(customer.id)
    print(BOLD(f"  Recent Events ({len(events)}):"))
    for e in events[:10]:
        print(f"    [{e.occurred_at:%Y-%m-%d}] {e.event_type}  val={e.value}  {e.notes}")

    alerts = engine.list_alerts(customer_id=customer.id, resolved=False)
    print(BOLD(f"\n  Open Alerts ({len(alerts)}):"))
    for a in alerts:
        print(f"    [{a.severity.value.upper()}] {a.alert_type.value}: {a.message}")

    playbooks = engine.recommend_playbooks(customer.id)
    print(BOLD(f"\n  Recommended Playbooks ({len(playbooks)}):"))
    for p in playbooks:
        print(f"    • {p.name}: {p.description}")
    print()


def cmd_event_record(engine: CSEngine, args: argparse.Namespace) -> None:
    occurred_at = (
        datetime.datetime.fromisoformat(args.at)
        if args.at
        else datetime.datetime.now(datetime.timezone.utc)
    )
    event = EngagementEvent(
        id=0,
        customer_id=args.customer_id,
        event_type=args.type,
        value=args.value,
        occurred_at=occurred_at,
        notes=args.notes or "",
    )
    saved = engine.record_event(event)
    customer = engine.get_customer(args.customer_id)
    score_str = _score_colour(customer.health_score) if customer else "?"
    print(
        f"Event recorded [#{saved.id}]: {saved.event_type} for customer #{saved.customer_id}. "
        f"New health score: {score_str}"
    )


def cmd_alert_list(engine: CSEngine, args: argparse.Namespace) -> None:
    resolved: Optional[bool] = None
    if args.open:
        resolved = False
    elif args.resolved:
        resolved = True
    alerts = engine.list_alerts(
        customer_id=args.customer_id or None,
        resolved=resolved,
    )
    if not alerts:
        print("No alerts found.")
        return
    print(f"{'ID':<4} {'CustID':<7} {'Severity':<10} {'Type':<25} {'Message':<50}")
    print("-" * 100)
    for a in alerts:
        status = "✓" if a.resolved else " "
        print(
            f"{a.id:<4} {a.customer_id:<7} {a.severity.value:<10} "
            f"{a.alert_type.value:<25} {a.message:<50} {status}"
        )


def cmd_alert_resolve(engine: CSEngine, args: argparse.Namespace) -> None:
    engine.resolve_alert(args.id)
    print(f"Alert #{args.id} marked as resolved.")


def cmd_playbook_add(engine: CSEngine, args: argparse.Namespace) -> None:
    churn_risk = ChurnRisk(args.churn_risk) if args.churn_risk else None
    playbook = Playbook(
        id=0,
        name=args.name,
        trigger_churn_risk=churn_risk,
        trigger_alert_type=None,
        description=args.description or "",
        action_steps=args.steps or "",
    )
    saved = engine.add_playbook(playbook)
    print(f"Playbook added [#{saved.id}]: {saved.name}")


def cmd_playbook_list(engine: CSEngine, _args: argparse.Namespace) -> None:
    playbooks = engine.list_playbooks()
    if not playbooks:
        print("No playbooks defined.")
        return
    for p in playbooks:
        risk = p.trigger_churn_risk.value if p.trigger_churn_risk else "all"
        print(f"[#{p.id}] {BOLD(p.name)}  (risk: {risk})")
        print(f"  {p.description}")
        if p.action_steps:
            steps = p.action_steps.replace("\\n", "\n").splitlines()
            for step in steps:
                print(f"    • {step}")
        print()


def cmd_run(engine: CSEngine, _args: argparse.Namespace) -> None:
    print("Running CS Engine across all customers…")
    results = engine.run()
    if not results:
        print("No customers to process.")
        return
    for customer, alerts in results:
        tag = _risk_colour(customer.churn_risk)
        score = _score_colour(customer.health_score)
        print(f"  [{customer.id}] {customer.name:<25}  health={score}  risk={tag}  alerts={len(alerts)}")
    total_alerts = sum(len(a) for _, a in results)
    print(f"\nDone. {len(results)} customer(s) processed, {total_alerts} new alert(s) generated.")


# ------------------------------------------------------------------
# Argument parser
# ------------------------------------------------------------------

def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="cs-engine",
        description="Customer Success Engine – track customer health and reduce churn.",
    )
    parser.add_argument(
        "--db",
        metavar="PATH",
        help="Path to the SQLite database file (default: ~/.cs_engine/cs_engine.db).",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    # --- customer ---
    customer_p = sub.add_parser("customer", help="Manage customers.")
    csub = customer_p.add_subparsers(dest="subcommand", required=True)

    add_p = csub.add_parser("add", help="Add a new customer.")
    add_p.add_argument("--name", required=True)
    add_p.add_argument("--email", required=True)
    add_p.add_argument("--plan", default="starter")
    add_p.add_argument("--mrr", type=float, default=0.0, metavar="USD")
    add_p.add_argument("--contract-start", dest="contract_start", required=True, metavar="YYYY-MM-DD")
    add_p.add_argument("--contract-end", dest="contract_end", required=True, metavar="YYYY-MM-DD")
    add_p.add_argument("--nps", type=int, default=None, metavar="-100..100")

    csub.add_parser("list", help="List all customers.")

    show_p = csub.add_parser("show", help="Show customer detail.")
    show_p.add_argument("id", type=int)

    # --- event ---
    event_p = sub.add_parser("event", help="Record engagement events.")
    esub = event_p.add_subparsers(dest="subcommand", required=True)

    rec_p = esub.add_parser("record", help="Record an engagement event.")
    rec_p.add_argument("--customer-id", dest="customer_id", type=int, required=True)
    rec_p.add_argument("--type", required=True, metavar="login|feature_use|support_ticket|nps_response")
    rec_p.add_argument("--value", type=float, default=1.0)
    rec_p.add_argument("--at", default=None, metavar="YYYY-MM-DDTHH:MM:SS")
    rec_p.add_argument("--notes", default="")

    # --- alert ---
    alert_p = sub.add_parser("alert", help="Manage alerts.")
    asub = alert_p.add_subparsers(dest="subcommand", required=True)

    list_alert = asub.add_parser("list", help="List alerts.")
    list_alert.add_argument("--customer-id", dest="customer_id", type=int, default=0)
    list_alert_group = list_alert.add_mutually_exclusive_group()
    list_alert_group.add_argument("--open", action="store_true")
    list_alert_group.add_argument("--resolved", action="store_true")

    res_p = asub.add_parser("resolve", help="Resolve an alert.")
    res_p.add_argument("id", type=int)

    # --- playbook ---
    pb_p = sub.add_parser("playbook", help="Manage playbooks.")
    pbsub = pb_p.add_subparsers(dest="subcommand", required=True)

    pb_add = pbsub.add_parser("add", help="Add a playbook.")
    pb_add.add_argument("--name", required=True)
    pb_add.add_argument("--churn-risk", dest="churn_risk", choices=["low", "medium", "high"], default=None)
    pb_add.add_argument("--description", default="")
    pb_add.add_argument("--steps", default="", help="Newline-separated action steps.")

    pbsub.add_parser("list", help="List all playbooks.")

    # --- run ---
    sub.add_parser("run", help="Refresh all customer health scores and generate alerts.")

    return parser


def main(argv=None) -> None:
    parser = build_parser()
    args = parser.parse_args(argv)

    db_path = Path(args.db) if args.db else None
    engine = CSEngine(db_path=db_path)

    try:
        dispatch = {
            ("customer", "add"): cmd_customer_add,
            ("customer", "list"): cmd_customer_list,
            ("customer", "show"): cmd_customer_show,
            ("event", "record"): cmd_event_record,
            ("alert", "list"): cmd_alert_list,
            ("alert", "resolve"): cmd_alert_resolve,
            ("playbook", "add"): cmd_playbook_add,
            ("playbook", "list"): cmd_playbook_list,
            ("run", None): cmd_run,
        }
        subcommand = getattr(args, "subcommand", None)
        handler = dispatch.get((args.command, subcommand))
        if handler:
            handler(engine, args)
        else:
            parser.print_help()
    finally:
        engine.close()


if __name__ == "__main__":
    main()
