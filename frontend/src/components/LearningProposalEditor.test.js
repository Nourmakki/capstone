import { problemsWith } from "./LearningProposalEditor";

/*
| What her validator will accept, checked here so a manager finds out in the
| form rather than from a Pydantic error after the round trip.
|
| The fixtures are shaped like her response models - QuizResultResponse,
| ProjectResultResponse, ExternalResourcesResultResponse - because that is
| what a run returns and what an edit sends back.
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
    resources: [
        {
            title: "React docs",
            url: "https://react.dev/learn",
            resource_type: "official_documentation",
            source_name: "React",
            description: "The official guide.",
            why_recommended: "It is the primary source.",
        },
    ],
});

describe("a proposal her validator would accept", () => {
    test("quiz", () => expect(problemsWith("quiz", quiz())).toEqual([]));
    test("project", () => expect(problemsWith("project", project())).toEqual([]));
    test("resources", () => expect(problemsWith("external_resources", resources())).toEqual([]));
});

describe("quiz", () => {
    test("must be exactly ten questions", () => {
        const short = quiz();
        short.questions = short.questions.slice(0, 9);

        expect(problemsWith("quiz", short)).toContain(
            "A quiz is exactly 10 questions - this has 9."
        );
    });

    test("names the question with an empty option", () => {
        const broken = quiz();
        broken.questions[3].options[2].text = "   ";

        expect(problemsWith("quiz", broken)).toContain("Question 4 has an empty option.");
    });

    test("refuses two options that read the same", () => {
        const broken = quiz();
        broken.questions[0].options[1].text = "  a1  ";

        expect(problemsWith("quiz", broken)).toContain(
            "Question 1 has two options that read the same."
        );
    });

    test("refuses a repeated question id", () => {
        const broken = quiz();
        broken.questions[5].question_id = broken.questions[0].question_id;

        expect(problemsWith("quiz", broken)).toContain("Two questions share an id.");
    });

    test("wants an explanation", () => {
        const broken = quiz();
        broken.questions[9].correct_answer_explanation = "";

        expect(problemsWith("quiz", broken)).toContain(
            "Question 10 does not say why its answer is right."
        );
    });
});

describe("project", () => {
    test("wants three tasks", () => {
        const broken = project();
        broken.tasks = ["Only one", "And two"];

        expect(problemsWith("project", broken)).toContain("Tasks needs at least 3 lines.");
    });

    test("wants one requirement", () => {
        const broken = project();
        broken.technical_requirements = [];

        expect(problemsWith("project", broken)).toContain("Requirements needs at least 1 line.");
    });

    test("refuses a repeated line", () => {
        const broken = project();
        broken.acceptance_criteria = ["It adds", "it ADDS ", "It survives"];

        expect(problemsWith("project", broken)).toContain("Done when repeats itself.");
    });

    test("refuses an empty line", () => {
        const broken = project();
        broken.tasks = ["Render a list", "  ", "Mark one done"];

        expect(problemsWith("project", broken)).toContain("Tasks has an empty line.");
    });

    test("wants the prose fields", () => {
        const broken = project();
        broken.overview = "";

        expect(problemsWith("project", broken)).toContain("The project needs an overview.");
    });
});

describe("resources", () => {
    test("at most three", () => {
        const broken = resources();
        broken.resources = Array.from({ length: 4 }, (_, i) => ({
            ...resources().resources[0],
            url: `https://example.com/${i}`,
        }));

        expect(problemsWith("external_resources", broken)).toContain("At most 3 resources.");
    });

    test("refuses two of the same link", () => {
        const broken = resources();
        broken.resources = [resources().resources[0], resources().resources[0]];

        expect(problemsWith("external_resources", broken)).toContain(
            "Two resources point at the same link."
        );
    });

    test("names the missing field", () => {
        const broken = resources();
        broken.resources[0].why_recommended = "";

        expect(problemsWith("external_resources", broken)).toContain(
            "Resource 1 needs a reason it was picked."
        );
    });

    test("none at all is allowed - her model sets only a maximum", () => {
        expect(problemsWith("external_resources", { node_id: 1, resources: [] })).toEqual([]);
    });
});

/*
| The payload the page builds around this. Her review model forbids extra
| keys and wants exactly one *_result under data, so the stage name and the
| key have to agree.
*/
describe("the edit payload", () => {
    const payloadFor = (stage, edited) => ({
        action: "edit",
        stage,
        data: { [`${stage}_result`]: edited },
    });

    test.each([
        ["quiz", "quiz_result"],
        ["project", "project_result"],
        ["external_resources", "external_resources_result"],
    ])("%s carries only %s", (stage, key) => {
        const payload = payloadFor(stage, { node_id: 1 });

        expect(Object.keys(payload).sort()).toEqual(["action", "data", "stage"]);
        expect(Object.keys(payload.data)).toEqual([key]);
        expect(payload.feedback).toBeUndefined();
    });
});
