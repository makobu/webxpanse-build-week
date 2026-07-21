# Build Week feature and evidence manifest

This manifest makes the judge-facing Clarity scope directly inspectable in the public snapshot. WebXpanse existed before Build Week; the event contribution is the focused founder operating rhythm assembled from the existing platform foundation.

## Runtime path

| Responsibility | Public source |
| --- | --- |
| Assemble source-labelled business and page context | services/AIContextAssemblyService.php |
| Build a stable business-context snapshot and readiness score | services/BusinessContextSnapshotService.php |
| Normalize founder-attention signals | services/FounderContextAttentionSignalProvider.php, services/FounderLoopAttentionSignalProvider.php, services/FounderWorkAttentionSignalProvider.php |
| Rank current evidence deterministically | services/FounderAttentionRankingService.php |
| Compose one constraint, at most three founder items, one commitment, and verified activity | services/FounderCommandCenterService.php |
| Limit the command center to the intended founder workspace | services/FounderCommandCenterAccessService.php |
| Render the command center and pass its exact visible evidence to Clarity | public/dashboard.php, api/dashboard/founder_command_center.php |
| Request and normalize the OpenAI explanation, with an evidence-backed fallback when the provider is unavailable | api/chat/ask.php, services/AIService.php, services/ClarityDeterministicFallbackService.php, services/ClarityResponsePresentationService.php |
| Create and guard the temporary judging workspace | scripts/create_build_week_demo.php, services/PresentationWorkspaceService.php, services/PresentationWorkspaceGuardService.php, services/PresentationWorkspaceCapabilityService.php, services/PresentationSessionService.php |
| Seed the coherent solo-founder scenario | services/PresentationSeedPackService.php |
| Preserve mobile decision-review boundaries | api/mobile/decision_center/ |

## Verification path

| Invariant | Focused evidence |
| --- | --- |
| Business context has stable identity, source freshness, conflicts, and readiness | tests/Unit/Services/BusinessContextSnapshotServiceTest.php |
| The command center keeps the decision surface intentionally small | tests/Unit/Services/FounderCommandCenterServiceTest.php |
| Access is limited to the intended founder workspace | tests/Unit/Services/FounderCommandCenterAccessServiceTest.php |
| The visible constraint, reason, and evidence reach Clarity | tests/Unit/Frontend/FounderCommandCenterHandoffTest.php |
| OpenAI Responses payloads and normalized outputs follow the expected contract | tests/Unit/Services/AIServiceResponsesTest.php |
| Provider-unavailable explanations preserve evidence, founder judgment, and the next measurable action | tests/Unit/Services/ClarityDeterministicFallbackServiceTest.php |
| Demo-only capabilities cannot be installed in normal workspaces | tests/Unit/Services/PresentationFounderWorkspaceServiceTest.php |
| Mobile review actions retain authentication, workspace, permission, and state gates | tests/Unit/Api/MobileDecisionCenterApiContractTest.php |
| A clone under the documented repository name generates correct public and API URLs | tests/Unit/Config/BasePathResolutionTest.php |

## Safety boundary

Deterministic services collect and rank evidence. The OpenAI model explains that evidence. The founder decides. Work appears as handled only when execution evidence exists. Presentation capabilities are isolated, time-limited, resettable, and simulation-first; they do not arm live message delivery.

Temporary developer token issuers, real credentials, uploads, logs, exports, backups, and local archives are not part of the public evaluation surface.

## Provenance note

The original private repository contains the multi-month product history and the Build Week milestone commits listed in [BUILD_WEEK_CHANGELOG.md](BUILD_WEEK_CHANGELOG.md). Those private hashes are disclosure references, not links that can be resolved from this clean public snapshot. The current public repository history and the files above are the authoritative judge-facing source.

Dennis Makobu is the sole builder. Codex with GPT-5.6 supported implementation, debugging, testing, browser verification, and submission preparation; Dennis made the product and safety decisions.
