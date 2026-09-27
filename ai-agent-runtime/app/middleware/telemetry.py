from __future__ import annotations

import logging
import os

from opentelemetry import trace
from opentelemetry.sdk.resources import Resource
from opentelemetry.sdk.trace import TracerProvider
from opentelemetry.sdk.trace.export import BatchSpanProcessor, ConsoleSpanExporter

logger = logging.getLogger(__name__)


def setup_telemetry(service_name: str, otlp_endpoint: str | None = None) -> None:
    resource = Resource.create({"service.name": service_name})
    provider = TracerProvider(resource=resource)
    # Always attach console exporter for local visibility; OTLP when configured.
    provider.add_span_processor(BatchSpanProcessor(ConsoleSpanExporter()))
    if otlp_endpoint:
        try:
            from opentelemetry.exporter.otlp.proto.http.trace_exporter import OTLPSpanExporter

            provider.add_span_processor(BatchSpanProcessor(OTLPSpanExporter(endpoint=otlp_endpoint)))
        except Exception as exc:  # pragma: no cover
            logger.warning("OTLP exporter not available: %s", exc)
    trace.set_tracer_provider(provider)
    os.environ.setdefault("OTEL_SERVICE_NAME", service_name)


def get_tracer(name: str = "ai-agent-runtime"):
    return trace.get_tracer(name)
