import React from "react";
import { render, screen, fireEvent, within } from "@testing-library/react";
import SubmissionCard from "./SubmissionCard";

/*
| A hand-in reads as a card: the mark and the way to ask for a person, with
| the assessment folded away until it is asked for.
*/

const graded = {
    id: 1,
    created_at: "2026-09-01T10:00:00Z",
    status: "graded",
    github_url: "https://github.com/someone/task-tracker",
    has_file: true,
    ai_score: 87,
    ai_feedback: "The list renders correctly but nothing persists on reload.",
    human_reviewed: false,
    score: null,
    comments: null,
};

const reviewed = {
    ...graded,
    id: 2,
    ai_score: 87,
    human_reviewed: true,
    score: 92,
    comments: "Better than the estimate - the persistence is there, just late.",
};

const pending = {
    ...graded,
    id: 3,
    status: "pending",
    ai_score: null,
    ai_feedback: null,
};

const show = (submission, expanded = false, handlers = {}) =>
    render(
        <SubmissionCard
            submission={submission}
            expanded={expanded}
            onToggle={handlers.onToggle ?? (() => {})}
            onRequestReview={handlers.onRequestReview ?? (() => {})}
        />
    );

describe("closed", () => {
    test("shows the mark and the way to ask for a person", () => {
        show(graded);

        expect(screen.getByText("87")).toBeInTheDocument();
        expect(screen.getByText("/ 100")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: /request professor review/i })).toBeInTheDocument();
    });

    test("keeps the assessment folded away", () => {
        show(graded);

        expect(screen.queryByText(/nothing persists on reload/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/github\.com/)).not.toBeInTheDocument();
    });

    test("a professor's grade is the one shown", () => {
        show(reviewed);

        expect(screen.getByText("92%")).toBeInTheDocument();
        expect(screen.getByText(/professor reviewed/i)).toBeInTheDocument();

        // Already reviewed, so there is nobody left to ask.
        expect(screen.queryByRole("button", { name: /request professor review/i })).not.toBeInTheDocument();
    });

    test("an ungraded hand-in says where it has got to", () => {
        show(pending);

        expect(screen.getByText("pending")).toBeInTheDocument();
        expect(screen.queryByText("87")).not.toBeInTheDocument();
    });
});

describe("open", () => {
    test("gives back the assessment and the link", () => {
        show(graded, true);

        expect(screen.getByText(/nothing persists on reload/i)).toBeInTheDocument();
        expect(screen.getByText("https://github.com/someone/task-tracker")).toBeInTheDocument();
        expect(screen.getByText("File attached")).toBeInTheDocument();
        expect(screen.getByText(/estimated grade/i)).toBeInTheDocument();
    });

    test("a reviewed one carries the professor's comments", () => {
        show(reviewed, true);

        expect(screen.getByText(/the persistence is there/i)).toBeInTheDocument();
        expect(screen.getByText(/professor grade/i)).toBeInTheDocument();

        // The estimate is still readable underneath - it is what the review
        // was asked about.
        expect(screen.getByText(/estimated grade/i)).toBeInTheDocument();
    });
});

describe("the controls", () => {
    test("the header opens it", () => {
        const onToggle = jest.fn();

        show(graded, false, { onToggle });

        fireEvent.click(screen.getByRole("button", { expanded: false }));

        expect(onToggle).toHaveBeenCalledTimes(1);
    });

    test("asking for a review does not also open the card", () => {
        const onToggle = jest.fn();
        const onRequestReview = jest.fn();

        show(graded, false, { onToggle, onRequestReview });

        fireEvent.click(screen.getByRole("button", { name: /request professor review/i }));

        expect(onRequestReview).toHaveBeenCalledTimes(1);
        expect(onToggle).not.toHaveBeenCalled();
    });

    /**
     * A button inside a button is markup a browser will not honour - it
     * rearranges the DOM, and the inner one stops being clickable. The
     * request button has to sit outside the one that folds the card.
     */
    test("the toggle contains no button of its own", () => {
        show(graded);

        const toggle = screen.getByRole("button", { expanded: false });

        expect(within(toggle).queryAllByRole("button")).toEqual([]);
    });
});
