/**
 * A version's modules as a flat list a dropdown can show, choices included.
 *
 * The graph endpoint returns rail modules with their choices nested inside
 * them, which is right for drawing a roadmap and wrong for picking one thing
 * out of it: a choice carries its own tutorials, quizzes and projects, so a
 * list that showed only rail modules put all of that material out of reach.
 *
 * Choices follow the module offering them, and are marked so "HTML" does not
 * sit in the list looking like a step of its own.
 */
export default function flattenModules(nodes = []) {
    const flat = [];

    nodes.forEach((node) => {
        flat.push({
            ...node,
            depth: 0,
            label: node.title,
        });

        (node.choices ?? []).forEach((choice) => {
            flat.push({
                ...choice,
                depth: 1,
                // Named with its parent: two modules can each offer a "Basics".
                label: `${node.title} › ${choice.title}`,
            });
        });
    });

    return flat;
}
