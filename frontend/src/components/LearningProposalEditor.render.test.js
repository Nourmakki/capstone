import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import LearningProposalEditor from "./LearningProposalEditor";

/*
| The forms themselves: that they show what the run proposed, that an edit
| reaches onSave, and that Save stays out while the edit is one her validator
| would refuse.
*/

const question = (n) => ({
    question_id: `q${n}`,
    question_type: "conceptual",
    question: `Question number ${n}?`,
    options: [
        { key: "A", text: `A${n}` },
        { key: "B", text: `B${n}` },
        { key: "C", text: `C${n}` },
        { key: "D", text: `D${n}` },
    ],
    correct_option: "A",
    correct_answer_explanation: `Because of reason ${n}.`,
    points: 10,
});

const quiz = () => ({
    node_id: 1,
    tutorial_resource_id: "vid-1",
    questions: Array.from({ length: 10 }, (_, i) => question(i + 1)),
    max_score: 100,
});

const project = () => ({
    node_id: 1,
    tutorial_resource_id: "vid-1",
    activity_type: "project",
    title: "Task tracker",
    overview: "Build a task tracker.",
    activity_context: "You have just finished component state.",
    learning_objective: "Lifting state.",
    tasks: ["Render a list", "Add a form", "Mark one done"],
    technical_requirements: ["React 18"],
    constraints: ["No backend"],
    deliverables: ["A repository"],
    acceptance_criteria: ["It adds", "It completes", "It survives a refresh"],
});

const resources = () => ({
    node_id: 1,
    resources: [{
        title: "React docs",
        url: "https://react.dev/learn",
        resource_type: "official_documentation",
        source_name: "React",
        description: "The official guide.",
        why_recommended: "It is the primary source.",
    }],
});

const saveButton = () => screen.getByRole("button", { name: /save this stage/i });

test("the quiz form shows every question the run proposed", () => {
    render(<LearningProposalEditor stage="quiz" value={quiz()} busy={false} onCancel={() => {}} onSave={() => {}} />);

    expect(screen.getByText("Question 1")).toBeInTheDocument();
    expect(screen.getByText("Question 10")).toBeInTheDocument();
    expect(screen.getByDisplayValue("Question number 4?")).toBeInTheDocument();
    expect(screen.getByDisplayValue("A7")).toBeInTheDocument();
    expect(saveButton()).toBeEnabled();
});

test("an edited question reaches onSave, and nothing else changes", () => {
    const onSave = jest.fn();

    render(<LearningProposalEditor stage="quiz" value={quiz()} busy={false} onCancel={() => {}} onSave={onSave} />);

    fireEvent.change(screen.getByDisplayValue("Question number 1?"), {
        target: { value: "A better first question?" },
    });

    fireEvent.click(saveButton());

    expect(onSave).toHaveBeenCalledTimes(1);

    const edited = onSave.mock.calls[0][0];

    expect(edited.questions[0].question).toBe("A better first question?");
    expect(edited.questions[1].question).toBe("Question number 2?");

    // Her model forbids extra keys, so the envelope has to survive untouched.
    expect(Object.keys(edited).sort()).toEqual(
        ["max_score", "node_id", "questions", "tutorial_resource_id"]
    );
    expect(edited.node_id).toBe(1);
    expect(edited.max_score).toBe(100);
    expect(edited.questions[0].points).toBe(10);
});

test("clicking a letter moves the correct answer", () => {
    const onSave = jest.fn();

    render(<LearningProposalEditor stage="quiz" value={quiz()} busy={false} onCancel={() => {}} onSave={onSave} />);

    fireEvent.click(screen.getAllByTitle("Mark C correct")[0]);
    fireEvent.click(saveButton());

    expect(onSave.mock.calls[0][0].questions[0].correct_option).toBe("C");
});

test("Save stays out while an option is empty, and says which question", () => {
    render(<LearningProposalEditor stage="quiz" value={quiz()} busy={false} onCancel={() => {}} onSave={() => {}} />);

    fireEvent.change(screen.getByDisplayValue("B2"), { target: { value: "  " } });

    expect(screen.getByText("Question 2 has an empty option.")).toBeInTheDocument();
    expect(saveButton()).toBeDisabled();
});

test("the project form edits one of its lists", () => {
    const onSave = jest.fn();

    render(<LearningProposalEditor stage="project" value={project()} busy={false} onCancel={() => {}} onSave={onSave} />);

    expect(screen.getByDisplayValue("Task tracker")).toBeInTheDocument();

    fireEvent.change(screen.getByDisplayValue("Add a form"), {
        target: { value: "Add a form that appends a task" },
    });

    fireEvent.click(saveButton());

    const edited = onSave.mock.calls[0][0];

    expect(edited.tasks).toEqual([
        "Render a list", "Add a form that appends a task", "Mark one done",
    ]);
    expect(edited.activity_type).toBe("project");
    expect(edited.tutorial_resource_id).toBe("vid-1");
});

test("dropping a task below three disables Save", () => {
    render(<LearningProposalEditor stage="project" value={project()} busy={false} onCancel={() => {}} onSave={() => {}} />);

    fireEvent.click(screen.getByRole("button", { name: "Remove Tasks line 1" }));

    expect(screen.getByText("Tasks needs at least 3 lines.")).toBeInTheDocument();
    expect(saveButton()).toBeDisabled();
});

test("the resources form adds one, capped at three", () => {
    render(<LearningProposalEditor stage="external_resources" value={resources()} busy={false} onCancel={() => {}} onSave={() => {}} />);

    expect(screen.getByDisplayValue("React docs")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /add a resource/i }));
    fireEvent.click(screen.getByRole("button", { name: /add a resource/i }));

    expect(screen.getByText("Resource 3")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /add a resource/i })).not.toBeInTheDocument();

    // The two new ones are blank, so it cannot be sent yet.
    expect(saveButton()).toBeDisabled();
});

test("cancel does not save", () => {
    const onCancel = jest.fn();
    const onSave = jest.fn();

    render(<LearningProposalEditor stage="project" value={project()} busy={false} onCancel={onCancel} onSave={onSave} />);

    fireEvent.change(screen.getByDisplayValue("Task tracker"), { target: { value: "Changed" } });
    fireEvent.click(screen.getByRole("button", { name: /cancel/i }));

    expect(onCancel).toHaveBeenCalled();
    expect(onSave).not.toHaveBeenCalled();
});
