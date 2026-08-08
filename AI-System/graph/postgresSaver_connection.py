# Creates the PostgresSaver connection
import os
from contextlib import contextmanager
from typing import Iterator
from dotenv import load_dotenv
from langgraph.checkpoint.postgres import PostgresSaver

# Load variables from the project's .env file.
load_dotenv()

def get_database_url() -> str:
    """
    Read and validate the PostgreSQL connection URL.
    """

    database_url = os.getenv(
        "LANGGRAPH_DATABASE_URL"
    )

    if not database_url:
        raise RuntimeError(
            "LANGGRAPH_DATABASE_URL is missing. "
            "Add it to the .env file."
        )

    return database_url


@contextmanager
def open_checkpointer() -> Iterator[PostgresSaver]:
    """
    Open a PostgreSQL-backed LangGraph checkpointer.

    The database connection remains open while code inside
    the `with open_checkpointer()` block is running.
    """

    database_url = get_database_url()

    with PostgresSaver.from_conn_string(
        database_url
    ) as checkpointer:
        yield checkpointer


def setup_checkpoint_database() -> None:
    """
    Create or update LangGraph's internal PostgreSQL tables.

    This must be called during the first setup and after
    relevant package upgrades that introduce migrations.
    """

    with open_checkpointer() as checkpointer:
        checkpointer.setup()