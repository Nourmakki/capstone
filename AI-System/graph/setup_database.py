# Calls checkpointer.setup() once

from .postgresSaver_connection import setup_checkpoint_database


def main() -> None:
    print(
        "Initializing LangGraph checkpoint database..."
    )

    setup_checkpoint_database()

    print(
        "LangGraph checkpoint database initialized successfully."
    )


if __name__ == "__main__":
    main()