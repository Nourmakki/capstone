import json
import re
from typing import Any
from pydantic import (BaseModel,ConfigDict,Field,model_validator,)
from agents.llm import llm
from learning_workflow.schema import (
    NonEmptyString,
    TutorialAgentInput,
    TutorialAgentResult,
    TutorialResourceType,
)
from learning_workflow.tools.youtube_search import (search_youtube_candidates,)
from learning_workflow.tools.youtube_playlist import (fetch_playlist_video_ids,)
from learning_workflow.tools.youtube_metadata import (fetch_video_metadata,)
from learning_workflow.tools.youtube_transcript import (fetch_video_transcript,)
from learning_workflow.graph.state import (LearningAgentState,)

SHORTLIST_SIZE = 3


class TutorialShortlistItem(BaseModel):
    """
    One resource selected during metadata shortlisting.
    """

    model_config = ConfigDict(extra="forbid")
    resource_type: TutorialResourceType
    resource_id: NonEmptyString


class TutorialShortlistResult(BaseModel):
    """
    Internal result from the metadata shortlist step.
    """

    model_config = ConfigDict(extra="forbid")

    selected_candidates: list[
        TutorialShortlistItem
    ] = Field(
        min_length=1,
        max_length=SHORTLIST_SIZE,
    )

    @model_validator(mode="after")
    def validate_unique_candidates(
        self,
    ) -> "TutorialShortlistResult":

        resource_keys = [
            (
                candidate.resource_type,
                candidate.resource_id,
            )
            for candidate
            in self.selected_candidates
        ]

        if (
            len(set(resource_keys))
            != len(resource_keys)
        ):
            raise ValueError(
                "Tutorial shortlist must not "
                "contain duplicate resources."
            )

        return self

def build_node_terms(agent_input: TutorialAgentInput,) -> set[str]:
    text = (
        f"{agent_input.node.title} "
        f"{agent_input.node.description or ''}"
    ).lower()

    stop_words = {"the","and","for","with","from","into","this",
        "that","using","learn","learning","tutorial","course","video",}

    return {
        term
        for term in re.findall(
            r"[a-z0-9+#.]+",
            text,
        )
        if (len(term) >= 3 and term not in stop_words)
    }


def choose_playlist_transcript_video(
    videos: list[dict[str, Any]],
    agent_input: TutorialAgentInput,
) -> dict[str, Any] | None:

    if not videos:
        return None

    node_terms = build_node_terms(
        agent_input
    )

    def score_video(
        video: dict[str, Any],
    ) -> int:

        title = (
            video["title"]
            .lower()
        )

        description = (
            video.get("description")
            or ""
        ).lower()

        score = 0

        for term in node_terms:
            if term in title:
                score += 3

            if term in description:
                score += 1

        return score

    return max(videos,key=score_video,)

def validate_shortlist_against_evidence(
    shortlist: TutorialShortlistResult,
    candidate_metadata: list[
        dict[str, Any]
    ],
) -> None:

    available_resources = {
        (
            candidate["resource_type"],
            candidate["resource_id"],
        )
        for candidate in candidate_metadata
    }

    
    selected_count = len(
        shortlist.selected_candidates
    )

    max_allowed = min(
        SHORTLIST_SIZE,
        len(candidate_metadata),
    )

    if not 1 <= selected_count <= max_allowed:
        raise ValueError(
            "Tutorial shortlist must contain "
            f"between 1 and {max_allowed} resources."
        )

    for selected in (
        shortlist.selected_candidates
    ):
        resource_key = (
            selected.resource_type,
            selected.resource_id,
        )

        if resource_key not in available_resources:
            raise ValueError(
                "Tutorial shortlist selected a "
                "resource outside the supplied "
                "candidate metadata."
            )

TUTORIAL_SHORTLIST_SYSTEM_PROMPT = """
You shortlist YouTube learning resources for one Computer Science
roadmap node.

You receive:
- the roadmap node
- prerequisite nodes
- metadata for YouTube candidate resources

A candidate may be:
- one standalone video
- one playlist containing metadata for its available videos


SHORTLIST SIZE

Select up to 3 of the strongest candidate resources.

If 3 or more candidates are genuinely relevant and educationally
promising, select the best 3.

If only 1 or 2 candidates are genuinely relevant and educationally
promising, select only those candidates.

Do not include a weak, irrelevant, or poorly matched resource merely
to reach 3 candidates.

Never select more than 3 candidates.
CORE OBJECTIVE

Shortlist only the candidates that appear most likely to provide
genuine educational value for the student.

The goal is not merely to find videos that mention the roadmap topic.

The strongest candidates should appear capable of helping the student
develop real understanding, useful knowledge, and practical ability in
the concepts required by the roadmap node.

Do not shortlist a resource merely because:
- its title contains the node name
- it is popular
- it has many views or likes
- it comes from a popular channel
- it claims to be complete

Prefer resources whose available metadata strongly suggests meaningful,
substantial, and educational teaching.


EVALUATION CRITERIA
Evaluate each candidate using:

1. RELEVANCE

The resource should directly relate to the roadmap node and its required
concepts.

Reject resources that are substantially off-topic.


2. APPARENT COVERAGE

Treat the concepts stated in the roadmap node title and description as
the required learning scope for this node.

Prefer candidates whose metadata suggests that they cover as many of
those required concepts as possible.

A candidate that covers the node broadly and meaningfully should normally
be preferred over a candidate that teaches only part of the required
scope, even if the narrower candidate is popular or otherwise high
quality.

Pay particular attention to concepts that are explicitly named in the
node description.

Do not ignore an important required concept merely because the candidate
is strong in other areas.

4. PRACTICAL LEARNING

For practical Computer Science topics, prefer resources that appear to
help the student apply the concepts in practice.

A student should ideally gain knowledge that can be used, not only
memorized.


5. DIFFICULTY AND PREREQUISITES

The resource should be appropriate for the supplied node difficulty.

Prerequisite nodes represent knowledge the student may already know.

Do not penalize a candidate for assuming those prerequisites.


6. TECHNICAL QUALITY AND RECENCY

Prefer technically useful and reasonably current resources when outdated
information could harm learning.

Do not favor recent content merely because it is recent.


PLAYLISTS

Evaluate a playlist as one complete learning resource.

Use the metadata of its available videos collectively.

Different playlist videos may teach different parts of the roadmap node.

A playlist may be stronger than a standalone video when its videos
together appear to provide deeper or more complete learning.


POPULARITY

Views, likes, comments, and channel popularity are secondary signals only.

They may help distinguish candidates that already appear educationally
strong.

Never shortlist a weaker educational resource only because it is more
popular.

CHAPTER EVIDENCE

When chapter information is available, use chapter titles as additional
evidence of the topics and structure actually covered by the resource.

Chapters are stronger evidence of topic coverage than a broad resource
title alone.

However, a chapter title does not by itself prove teaching depth.

SELECTION RULE

Choose the candidates that appear most likely to become genuinely useful,
meaningful, and educational tutorials for the student after deeper
transcript evaluation.

This shortlist will be evaluated again using transcript evidence.

Select only resources supplied in the candidate metadata.

When choosing the shortlist, preserve candidates with the strongest
apparent overall coverage of the required node concepts so that deeper
transcript evaluation can compare them.

Do not invent resource IDs.

Do not combine candidates.

LANGUAGE

Only shortlist resources that appear to teach in English.

If the supplied title or metadata explicitly indicates that a resource
is taught in another language, do not shortlist it.

OUTPUT

Return ONLY valid JSON.

The selected_candidates array must contain between 1 and 3 candidates.

It may contain only 1 or 2 candidates when fewer than 3 supplied
resources are strong enough to justify deeper transcript evaluation, but maxiumum is 3.

Do not return explanations or any other fields.

Return:

{
  "selected_candidates": [
    {
      "resource_type": "video",
      "resource_id": "exact_resource_id"
    },
    {
      "resource_type": "playlist",
      "resource_id": "exact_resource_id"
    },
    {
      "resource_type": "video",
      "resource_id": "exact_resource_id"
    }
  ]
}

"""

# =====================================================================
# JSON EXTRACTION
# =====================================================================

def extract_json_object(content: Any,) -> dict[str, Any]:
    """
    Extract one JSON object from an LLM response.
    """

    if isinstance(content, dict):
        return content

    if isinstance(content, list):
        text_parts: list[str] = []

        for item in content:
            if isinstance(item, str):
                text_parts.append(item)

            elif isinstance(item, dict):
                if "text" in item:
                    text_parts.append(str(item["text"]))

                elif "content" in item:
                    text_parts.append(str(item["content"]))
                else:
                    text_parts.append(json.dumps(item,ensure_ascii=False,))
            else:
                text_parts.append(str(item))
        text = "\n".join(text_parts)
    else:
        text = str(content)

    text = text.strip()

    text = re.sub(r"^```(?:json)?\s*","",text,flags=re.IGNORECASE,)

    text = re.sub(r"\s*```$","",text,)
    try:
        parsed = json.loads(text)
        if not isinstance(parsed,dict,):
            raise ValueError(
                "Tutorial Agent output must "
                "be a JSON object."
            )

        return parsed

    except json.JSONDecodeError:
        pass

    match = re.search( r"\{.*\}", text,re.DOTALL,)

    if not match:
        raise ValueError(
            "No valid JSON object was found "
            "in Tutorial Agent response."
        )

    parsed = json.loads(match.group(0))
    if not isinstance(parsed,dict,):
        raise ValueError(
            "Tutorial Agent output must "
            "be a JSON object."
        )

    return parsed

def validate_result_against_evidence(
    result: TutorialAgentResult,
    shortlisted_evidence: list[
        dict[str, Any]
    ],
) -> None:

    if result.status == "learning_failed":
        return

    tutorial = result.tutorial

    if tutorial is None:
        raise ValueError(
            "A ready Tutorial Agent result must "
            "contain a tutorial."
        )

    matching_candidate = None

    for candidate in shortlisted_evidence:
        if (
            candidate["resource_type"]
            == tutorial.resource_type
            and candidate["resource_id"]
            == tutorial.resource_id
        ):
            matching_candidate = candidate
            break

    if matching_candidate is None:
        raise ValueError(
            "Tutorial Agent selected a resource "
            "outside the shortlisted evidence."
        )

    if (
        tutorial.title
        != matching_candidate[
            "resource_title"
        ]
    ):
        raise ValueError(
            "Tutorial Agent changed the "
            "selected resource title."
        )

    if (
        tutorial.url
        != matching_candidate[
            "resource_url"
        ]
    ):
        raise ValueError(
            "Tutorial Agent changed the "
            "selected resource URL."
        )

    if (
        tutorial.channel_name
        != matching_candidate[
            "resource_channel_name"
        ]
    ):
        raise ValueError(
            "Tutorial Agent changed the "
            "selected channel name."
        )

def build_selected_tutorial_quiz_evidence(
    result: TutorialAgentResult,
    shortlisted_evidence: list[
        dict[str, Any]
    ],
) -> dict[str, Any]:

    if (
        result.status != "ready"
        or result.tutorial is None
    ):
        raise ValueError(
            "Quiz evidence can only be built "
            "for a ready tutorial result."
        )

    selected_key = (
        result.tutorial.resource_type,
        result.tutorial.resource_id,
    )

    for candidate in shortlisted_evidence:
        candidate_key = (
            candidate["resource_type"],
            candidate["resource_id"],
        )

        if candidate_key == selected_key:
            return {
                "resource_type": (
                    candidate[
                        "resource_type"
                    ]
                ),
                "resource_id": (
                    candidate[
                        "resource_id"
                    ]
                ),
                "videos": (
                    candidate["videos"]
                ),
                "transcript_evidence": (
                    candidate.get(
                        "transcript_evidence"
                    )
                ),
            }

    raise ValueError(
        "Selected tutorial evidence was "
        "not found in the shortlisted evidence."
    )


# =====================================================================
# Start the runner
# =====================================================================
def run_tutorial_agent(
    agent_input: TutorialAgentInput | dict[str, Any],
) -> dict[str, Any]:
    """
    Find a strong YouTube tutorial for one roadmap node.
    """

    if isinstance(agent_input, dict):
        agent_input = TutorialAgentInput.model_validate(agent_input)

    elif not isinstance(agent_input,TutorialAgentInput,):
        raise TypeError(
            "agent_input must be a TutorialAgentInput "
            "or dictionary."
        )

    candidates = search_youtube_candidates(
        agent_input
    )

    if __name__ == "__main__":
        print()
        print("=" * 70)
        print("YOUTUBE SEARCH CANDIDATES")
        print("=" * 70)

        for index, candidate in enumerate(
            candidates,
            start=1,
        ):
            print(
                index,
                candidate.resource_type,
                candidate.resource_id,
                candidate.title,
            )


    if not candidates:
        tutorial_result = TutorialAgentResult(
            node_id=agent_input.node.id,
            status="learning_failed",
            tutorial=None,
            coverage=None,
            failure_reason=(
                "No tutorial "
                "candidates were found for this "
                "roadmap node."
            ),
        ).model_dump()

        return {
            "tutorial_result": tutorial_result,
        }

    candidate_metadata: list[dict[str, Any]] = []

    for candidate in candidates:
        if candidate.resource_type == "video":
            try:
                videos = fetch_video_metadata(
                    [candidate.resource_id]
                )

            except Exception:
                continue

            if not videos:
                continue

            video = videos[0]

            candidate_metadata.append(
                {
                    "resource_type": "video",
                    "resource_id": (
                        candidate.resource_id
                    ),
                    "resource_title": (
                        video.title
                    ),
                    "resource_url": (
                        video.url
                    ),
                    "resource_channel_name": (
                        video.channel_name
                    ),
                    "videos": [
                        video.model_dump()
                    ],
                }
            )

            continue

        if candidate.resource_type == "playlist":
            try:
                video_ids = (
                    fetch_playlist_video_ids(
                        candidate.resource_id
                    )
                )

            except Exception:
                continue

            if not video_ids:
                continue

            try:
                videos = fetch_video_metadata(
                    video_ids
                )

            except Exception:
                continue

            if not videos:
                continue

            videos_by_id = {
                video.video_id: video
                for video in videos
            }

            ordered_videos = [
                videos_by_id[
                    video_id
                ].model_dump()
                for video_id in video_ids
                if video_id in videos_by_id
            ]

            if not ordered_videos:
                continue

            candidate_metadata.append(
                {
                    "resource_type": "playlist",
                    "resource_id": (
                        candidate.resource_id
                    ),
                    "resource_title": (
                        candidate.title
                    ),
                    "resource_url": (
                        "https://www.youtube.com/"
                        "playlist?list="
                        f"{candidate.resource_id}"
                    ),
                    "resource_channel_name": (
                        candidate.channel_name
                    ),
                    "videos": ordered_videos,
                }
            )

    if not candidate_metadata:
        tutorial_result = TutorialAgentResult(
            node_id=agent_input.node.id,
            status="learning_failed",
            tutorial=None,
            coverage=None,
            failure_reason=(
                "YouTube tutorial candidates were "
                "found, but no usable English "
                "metadata could be retrieved."
            ),
        ).model_dump()

        return {
            "tutorial_result": tutorial_result,
        }

    shortlist_user_message = f"""
ROADMAP TOPIC

{agent_input.roadmap_topic}


ROADMAP NODE

{json.dumps(
    agent_input.node.model_dump(),
    indent=2,
    ensure_ascii=False,
)}


PREREQUISITE NODES

{json.dumps(
    [
        node.model_dump()
        for node
        in agent_input.prerequisite_nodes
    ],
    indent=2,
    ensure_ascii=False,
)}


YOUTUBE CANDIDATE METADATA

{json.dumps(
    candidate_metadata,
    indent=2,
    ensure_ascii=False,
)}
"""

    shortlist_messages = [
        {
            "role": "system",
            "content": (
                TUTORIAL_SHORTLIST_SYSTEM_PROMPT
            ),
        },
        {
            "role": "user",
            "content": (
                shortlist_user_message
            ),
        },
    ]

    shortlist_response = llm.invoke(
        shortlist_messages
    )

    shortlist_output = extract_json_object(
        shortlist_response.content
    )

    shortlist = (
        TutorialShortlistResult.model_validate(
            shortlist_output
        )
    )

    validate_shortlist_against_evidence(
        shortlist,
        candidate_metadata,
    )

    metadata_by_resource = {
        (
            candidate["resource_type"],
            candidate["resource_id"],
        ): candidate
        for candidate in candidate_metadata
    }

    shortlisted_metadata = [
        metadata_by_resource[
            (
                selected.resource_type,
                selected.resource_id,
            )
        ]
        for selected
        in shortlist.selected_candidates
    ]

    if __name__ == "__main__":
        print()
        print("=" * 70)
        print("LLM #1 SHORTLIST")
        print("=" * 70)

        for index, candidate in enumerate(
            shortlisted_metadata,
            start=1,
        ):
            print(
                index,
                candidate["resource_type"],
                candidate["resource_id"],
                candidate["resource_title"],
            )

    shortlisted_evidence: list[ dict[str, Any]] = []
    for candidate in shortlisted_metadata:
        transcript_target = None

        if candidate["resource_type"] == "video":
            transcript_target = (
                candidate["videos"][0]
            )
          
        elif candidate["resource_type"] == "playlist":
            transcript_target = (
                choose_playlist_transcript_video(
                    candidate["videos"],
                    agent_input,
                )
            )

        transcript = None

        if transcript_target is not None:
            transcript = fetch_video_transcript(
                transcript_target["video_id"]
            )

        transcript_evidence = None

        if transcript_target is not None:
            transcript_evidence = {
                "video_id": (
                    transcript_target["video_id"]
                ),
                "video_title": (
                    transcript_target["title"]
                ),
                "transcript": transcript,
            }

        shortlisted_evidence.append(
            {
                "resource_type": (
                    candidate["resource_type"]
                ),
                "resource_id": (
                    candidate["resource_id"]
                ),
                "resource_title": (
                    candidate["resource_title"]
                ),
                "resource_url": (
                    candidate["resource_url"]
                ),
                "resource_channel_name": (
                    candidate[
                        "resource_channel_name"
                    ]
                ),
                "videos": candidate["videos"],
                "transcript_evidence": (
                    transcript_evidence
                ),
            }
        )

    shortlisted_llm_evidence: list[
        dict[str, Any]
    ] = []

    for candidate in shortlisted_evidence:

        candidate_for_llm = {
            **candidate
        }

        transcript_evidence = (
            candidate.get(
                "transcript_evidence"
            )
        )

        if transcript_evidence is not None:

            transcript_for_llm = (
                transcript_evidence.get(
                    "transcript"
                )
            )

            if transcript_for_llm is not None:

                transcript_without_full_text = {
                    key: value
                    for key, value
                    in transcript_for_llm.items()
                    if key != "full_text"
                }

                candidate_for_llm[
                    "transcript_evidence"
                ] = {
                    **transcript_evidence,
                    "transcript": (
                        transcript_without_full_text
                    ),
                }

        shortlisted_llm_evidence.append(
            candidate_for_llm
        )

    final_user_message = f"""
ROADMAP TOPIC
{agent_input.roadmap_topic}

ROADMAP NODE

{json.dumps(
    agent_input.node.model_dump(),
    indent=2,
    ensure_ascii=False,
)}

PREREQUISITE NODES
{json.dumps(
    [
        node.model_dump()
        for node
        in agent_input.prerequisite_nodes
    ],
    indent=2,
    ensure_ascii=False,
)}

SHORTLISTED YOUTUBE TUTORIAL EVIDENCE

{json.dumps(
    shortlisted_llm_evidence,
    indent=2,
    ensure_ascii=False,
)}
"""

    final_messages = [
        {
            "role": "system",
            "content": (
                TUTORIAL_AGENT_SYSTEM_PROMPT
            ),
        },
        {
            "role": "user",
            "content": final_user_message,
        },
    ]

    final_response = llm.invoke(
        final_messages
    )

    parsed_output = extract_json_object(
        final_response.content
    )

    validated_result = (
        TutorialAgentResult.model_validate(
            parsed_output
        )
    )

    if (
        validated_result.node_id
        != agent_input.node.id
    ):
        raise ValueError(
            "Tutorial Agent returned a node_id "
            "that does not match the requested node."
        )

    validate_result_against_evidence(
        validated_result,
        shortlisted_evidence,
    )

    tutorial_result = (
        validated_result.model_dump()
    )

    output = {
        "tutorial_result": tutorial_result,
    }

    if validated_result.status == "ready":
        output["tutorial_quiz_evidence"] = (
            build_selected_tutorial_quiz_evidence(
                validated_result,
                shortlisted_evidence,
            )
        )

    return output



# =====================================================================
# TUTORIAL AGENT PROMPT
# =====================================================================

TUTORIAL_AGENT_SYSTEM_PROMPT = """
You are a Tutorial Agent for a Computer Science learning platform.

You receive one roadmap node and up to 3 shortlisted YouTube learning
resources.

Each resource is either:
- one standalone video
- one playlist

Your responsibility is to select exactly ONE resource that would
genuinely help the student learn the roadmap node.

Do not select a resource merely because it is the best of the available
options.

If none of the resources is educationally strong enough, return
learning_failed.

CORE REQUIREMENT

The selected tutorial must provide real educational value.

It should help the student gain meaningful understanding, useful
knowledge, and the ability to apply the main concepts required by the
roadmap node.

Do not select content that is merely related to the topic.

The resource should genuinely teach.

REQUIRED NODE CONCEPTS

Concepts explicitly named in the roadmap node description are required
learning concepts.

A resource does not need to cover every concept with equal depth, but it
must provide meaningful coverage of the major required concepts.

If important explicitly required concepts are completely missing, do not
claim that the resource fully covers the node.

If another candidate provides substantially better overall coverage of
the required concepts while maintaining strong educational quality,
prefer that candidate.

If the selected resource still leaves important required concepts
completely untaught and no supplied candidate adequately covers the node,
return learning_failed rather than lowering the educational standard.

EVIDENCE

Each shortlisted resource contains metadata.

Metadata may include:
- titles
- descriptions
- durations
- channels
- publication dates
- views
- likes
- comments

A resource may also contain transcript_evidence.

For a standalone video, transcript evidence comes from that video.

For a playlist, transcript evidence comes from one representative video
selected from that playlist.

Transcript evidence may be null if transcript retrieval failed or no
usable transcript was available.

A null transcript does not mean that the resource is bad.

When transcript evidence is available, use it as the strongest direct
evidence of actual teaching quality.

Transcript text may be sampled rather than complete.

For playlists, evaluate the complete playlist collectively using the
metadata of its supplied videos.

Do not assume that one representative transcript proves that every
playlist video has identical teaching quality.

Never invent metadata or transcript content.

CHAPTER EVIDENCE

When chapter information is available, use it as structural evidence of
which topics the resource actually contains.

Do not report an important node concept as unsupported when the supplied
chapter evidence clearly shows that the resource contains a meaningful
section devoted to that concept.

However, chapter titles show topic presence and structure, not teaching
depth.

Use transcript evidence, metadata, chapter structure, and duration
together when judging whether a concept is meaningfully taught.

EDUCATIONAL EVALUATION

Evaluate each resource against both:
- the roadmap node title
- the roadmap node description

Consider:

1. RELEVANCE

The tutorial must directly teach the roadmap node.
Reject substantially off-topic resources.

2. COVERAGE

The resource should cover the important concepts required by the node.
For playlists, different videos may collectively cover different parts
of the node.

Concepts explicitly stated in the node description are part of the
required scope.

Do not describe a resource as complete or fully covering the node if
important required concepts are missing.

If important concepts are missing, account for that clearly when
deciding whether the resource is strong enough to select.
If not_covered_node_concepts is not empty, do not describe the resource
as providing "full", "complete", "all", or "the full spectrum" of the
roadmap node.

Describe its coverage accurately and acknowledge meaningful gaps.

3. DEPTH

Prefer resources that explain concepts sufficiently for real learning.

Look for:
- clear explanations
- reasoning
- examples
- demonstrations
- implementation details
- structured teaching

Reject superficial summaries or content that mostly mentions concepts.


4. PRACTICAL LEARNING

For practical Computer Science topics, prefer resources that help the
student apply the concepts.

Useful evidence includes:
- coding examples
- demonstrations
- exercises
- implementation
- debugging
- practical examples
- projects

Do not infer a practical skill merely because the resource mentions the
corresponding concept. Include a practical skill only when the evidence
shows that the resource actually teaches the student how to perform,
apply, implement, analyze, or practice it.

5-TEACHING DEPTH

The selected resource must do more than mention or briefly summarize
the concepts related to the node.

Distinguish between:

- mentioning a concept
- giving a high-level overview of a concept
- meaningfully teaching a concept

For a broad roadmap node, a short overview that rapidly lists many
related concepts is not sufficient merely because it appears to have
broad coverage.

Prefer resources that spend enough instructional depth on the important
concepts for the student to develop real understanding.

Strong teaching evidence may include explanations of how or why concepts
work, examples, demonstrations, comparisons, implementation steps,
problem solving, or practical application.

Evaluate teaching depth relative to the scope of the node. A short
resource may be sufficient for a narrow concept, but a broad node usually
requires substantially more instructional depth.

If a resource mentions many required concepts but teaches them only
superficially, treat its educational coverage as shallow rather than
comprehensive.

If none of the shortlisted resources teaches the node with sufficient
depth to serve as the student's main tutorial, return learning_failed.

6. PREREQUISITES

Prerequisite nodes represent knowledge the student may already know.
Do not penalize a tutorial for assuming those prerequisites.


7. TECHNICAL QUALITY

Reject resources that are clearly:
- technically incorrect
- seriously misleading
- obsolete in ways that harm learning
- based on deprecated approaches when modern practice is required


8. STUDENT LEARNING VALUE

The final question is:

After completing this resource, would the student genuinely understand
and be able to use the main concepts required by the roadmap node?

If the answer is no, reject it.


TRANSCRIPT RULES

When transcript evidence is available, ignore non-educational content
such as:
- greetings
- channel introductions
- subscribe requests
- like requests
- sponsorships
- advertisements
- course promotions

Because transcripts may be sampled, absence of a concept from the
sample does not prove that the complete tutorial never teaches it.

Transcript availability alone must not determine which resource wins.


POPULARITY

Views, likes, comments, and channel popularity are secondary signals.

Never select a weaker educational resource only because it is more
popular.


SELECTION

Compare all shortlisted resources.

Select exactly ONE supplied resource.

The selected resource may be:
- one standalone video
- one playlist

Never combine multiple candidates.

Never invent or substitute another resource.

Use the exact supplied:
- resource_type
- resource_id
- resource_title
- resource_url
- resource_channel_name

Do not lower the educational standard merely because the available
resources are weak.

If no resource genuinely satisfies the roadmap node, return
learning_failed.

The selected resource should be capable of serving as the student's
main tutorial for this roadmap node.

Do not select a resource that teaches only a strong subset of the node
when another supplied resource meaningfully covers more of the required
learning scope.


SELECTION REASON

Write the selection_reason conservatively and based only on the supplied
metadata and transcript evidence.

Explain why this resource was preferred over the other shortlisted
candidates.

Do not use promotional or exaggerated language such as "excellent",
"comprehensive", "complete", or "perfect" unless the evidence clearly
supports that claim.

If the selected resource has meaningful limitations or missing areas,
acknowledge them briefly rather than overstating the resource's quality
or coverage.

The selection_reason should explain the actual educational tradeoff,
not advertise the tutorial.

NOT COVERED CONCEPTS

Only include a concept in not_covered_node_concepts when it is part of
the learning scope stated or clearly implied by the supplied roadmap node.

Do not add optional, advanced, or related topics merely because they are
commonly associated with the subject.

The purpose of not_covered_node_concepts is to report meaningful gaps
against this specific node, not to list everything the tutorial could
possibly have taught.

LANGUAGE

The selected tutorial must be taught in English.

Do not select a resource whose available evidence indicates that it is
taught in another language.

OUTPUT

Return ONLY valid JSON.

For a successful selection return:

{
  "node_id": 1,
  "status": "ready",
  "tutorial": {
    "resource_type": "video",
    "resource_id": "exact_resource_id",
    "title": "Exact supplied resource title",
    "url": "Exact supplied resource URL",
    "channel_name": "Exact supplied channel name",
    "selection_reason": "Why this resource genuinely helps the student learn the node."
  },
  "coverage": {
    "summary": "Overall educational coverage of the selected resource.",
    "covered_concepts": [
      "Concept supported by the available evidence"
    ],
    "practical_skills": [],
    "assumed_knowledge": [],
    "not_covered_node_concepts": []
  },
  "failure_reason": null
}

If no resource is educationally strong enough return:

{
  "node_id": 1,
  "status": "learning_failed",
  "tutorial": null,
  "coverage": null,
  "failure_reason": "Why none of the shortlisted resources was educationally strong enough."
}

Do not add text before or after the JSON.
"""

# =====================================================================
# LOCAL TEST
# =====================================================================

# =====================================================================
# LOCAL TEST
# =====================================================================

if __name__ == "__main__":
    sample_input = {
        "roadmap_topic": "Cybersecurity",
        "node": {
            "id": 60,
            "title": "Side-Channel Attacks",
            "description": (
                "Learn how attackers can infer sensitive information from "
                "indirect system behavior, understand common side-channel "
                "attack techniques, and learn how such attacks are analyzed "
                "and mitigated in secure systems."
            ),
            "difficulty": "advanced",
        },
        "prerequisite_nodes": [
            {
                "id": 58,
                "title": "Computer Architecture",
                "description": (
                    "Understand processors, memory hierarchies, caches, "
                    "instruction execution, and basic hardware behavior."
                ),
                "difficulty": "intermediate",
            },
            {
                "id": 59,
                "title": "Cybersecurity Fundamentals",
                "description": (
                    "Understand common attack techniques, vulnerabilities, "
                    "security principles, and defensive mechanisms."
                ),
                "difficulty": "beginner",
            },
        ],
    }

    result = run_tutorial_agent(
        sample_input
    )

    print()
    print("=" * 70)
    print("FINAL TUTORIAL AGENT RESULT")
    print("=" * 70)

    print(
        json.dumps(
            result,
            indent=2,
            ensure_ascii=False,
        )
    )