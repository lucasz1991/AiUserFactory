# ADR-001: Workflow Studio — Architektur-Konsolidierung und Optimierung

**Status:** Proposed
**Datum:** 2026-07-19
**Deciders:** Maintainer + Agents (Codex/Claude)
**Grundlage:** Verifizierter Multi-Agent-Architektur-Audit (26 bestätigte, 5 widerlegte Befunde; jeder Befund adversarial gegen den echten Code und die 10 README-Teamprotokoll-Regeln geprüft).

---

## 1. Kontext

Das **Workflow Studio** (`app/Livewire/Admin/Network/WorkflowStudio.php`, 1057 Z.) wurde in ~12 Commits zwischen `16e3fb0` (2026-07-17 22:29) und `82c3f5b` (2026-07-18 15:56) eingeführt — **nach** dem letzten Teamprotokoll-Eintrag (Session-12, 2026-07-16). Diese Arbeit ist im README nicht dokumentiert (verletzt Regel 8).

Entscheidend ist die Bauweise: **Das Studio wurde *auf* das bestehende Copilot-System gesetzt, nicht als Ersatz.** Daraus folgt die zentrale Architektur-Spannung, die alle anderen Befunde überlagert:

- **Drei Session-Begriffe** beschreiben denselben logischen Lauf: `WorkflowRun`, `WorkflowCopilotSession` (WCS), `WorkflowStudioSession` (WSS).
- **WSS dupliziert 16 der 20 `fillable`-Felder von WCS** (`WorkflowStudioSession.php:14-19` vs `WorkflowCopilotSession.php:53-74`). Die Migration `2026_07_17_210000` backfillt jede WCS 1:1 in eine neue WSS (L102-127) und kopiert **jedes Event, jede Revision, jeden Checkpoint** in parallele Studio-Tabellen (L133-203).
- **Doppelte Audit-Stacks:** `workflow_studio_events` **und** `workflow_copilot_events`; `WorkflowStudioRevision`/`WorkflowStudioRevisionService` **und** `WorkflowRevision`/`WorkflowRevisionService`; `WorkflowStudioCheckpoint` **und** `WorkflowRunCheckpoint` — je mit eigenem, unverknüpftem `sequence`-Zähler.
- Permission/Budget-State wird bei jeder Änderung **von Hand** zwischen WSS und WCS gespiegelt (`WorkflowStudioAuthorizationService.php:50-56`).

Diese Doppelung ist **die Wurzel der wiederkehrenden „Stillstand"-/Divergenz-Bugs**, die das Protokoll seit Session 2 durchgehend patcht (verspätete Jobs, verwaiste Checkpoints, `about:blank`-Wedges, „confirmation_required"-Hänger). Solange dieselbe Wahrheit an drei Orten liegt und per Hand synchron gehalten wird, erzeugt jedes neue Feature neue Divergenz-Pfade.

Dazu kommen vier orthogonale Problemfelder, alle code-belegt:
- **God-Objects:** `WorkflowCopilotSupervisorService` (3784 Z.), `WorkflowManager` (3575 Z.), `WorkflowCopilotRepairService` (3339 Z.).
- **Kopplung/DI:** `WorkflowStudio` löst 8 Services an 32 Stellen inline per `app(...)` auf (Service-Locator statt Injection) und mischt UI-State mit Multi-Aggregat-Orchestrierung.
- **Datenmodell/Integrität:** Ein Schema-Level-Loch, das den unveränderlichen Audit-Log (Regel 5) per Cascade-Delete zerstören kann.
- **Runtime/Performance:** Ein 2-Sekunden-Poll rendert die gesamte Query-Graph ohne Memoisierung, inkl. 500-Zeilen-`Person`-Liste und komplettem Event-Eager-Load pro Zyklus.

---

## 2. Entscheidung

**Wir behandeln das Studio als Optimierungsprogramm in vier Wellen und treffen eine zentrale Struktur-Entscheidung:** Die Session-Doppelung wird **stufenweise** auf **einen kanonischen Besitzer** zusammengeführt (kein Big-Bang-Rewrite; die FK-Brücke bleibt während der Migration bestehen). Bis dahin werden die akuten Korrektheits-, Integritäts- und Performance-Löcher sofort geschlossen und die God-Objects entlang sauberer Grenzen entkoppelt.

Reihenfolge nach Risiko/Aufwand (Details in §6):
1. **Welle 0 — Governance:** Studio-Arbeit im Protokoll nachtragen, diesen ADR annehmen.
2. **Welle 1 — Korrektheit/Integrität/Security** (P0, klein).
3. **Welle 2 — Performance-Quick-Wins** (P1, alle S/M).
4. **Welle 3 — Entkopplung/Dekomposition** (P2, M/L).
5. **Welle 4 — Struktur-Konsolidierung der Sessions** (P3, XL — die eigentliche Kernarbeit).

---

## 3. Optionen für die zentrale Session-Frage

### Option A — Ein kanonischer Session-Besitzer, stufenweise (empfohlen)

WSS wird der kanonische **UI-/Authoring-Aggregat**; WCS wird auf die **autonom-spezifischen Felder** reduziert (`phase`, `execution_target`, `repair_round`, `last_event_sequence`) und über die bestehende FK-Brücke gelesen. Ein einziger Setter besitzt den Permission/Budget-Spiegel; ein einziger Service (`WorkflowStudioSessionService`) besitzt die WSS↔WCS↔WR-Bindung.

| Dimension | Bewertung |
|---|---|
| Komplexität | Hoch (gestaffelt, aber viele Aufrufstellen) |
| Kosten | XL, über mehrere Wellen verteilbar |
| Skalierbarkeit | Beseitigt die 2×-Schreibpfade und die Divergenz-Klasse dauerhaft |
| Team-Vertrautheit | Gut — folgt der bereits gebauten Migrationsrichtung (WCS→WSS) |

**Pro:** Adressiert die Ursache; jedes künftige Session-Feature nur noch an einem Ort; entfernt die strukturelle Divergenz-Klasse.
**Contra:** WCS wird noch von ~6 Live-Services geschrieben (`SessionService`, `SupervisorService`, `RepairService`, `PlanningService`, `QueueRecoveryService`, `LaunchService`) → Migration muss gestaffelt sein, FK-Brücke bleibt.
**Wichtiger Constraint (verifiziert):** Vorwärts erzeugte Studio-Sessions haben `workflow_copilot_session_id = null` (`WorkflowStudioSessionService::open()` L41-63). „Alle geteilten Felder durch die FK lesen" **darf standalone Studio-Sessions nicht brechen** — WSS bleibt eigenständiger Aggregat für Nicht-Copilot-Sessions. `execution_target` als echte Spalte muss den WCS-Invarianten-Guard (`assertSystemExecutionTarget`) bewahren.

### Option B — Beide behalten, nur Guards + ein Bindungs-Besitzer

Kein Modell-Merge; stattdessen die Divergenz durch einen einzigen guarded Bindungs-Service, Single-Active-Guard und Ownership-Checks einhegen.

| Dimension | Bewertung |
|---|---|
| Komplexität | Mittel |
| Kosten | M–L |
| Skalierbarkeit | Dämmt Symptome ein, doppelte Speicher-/Schreibpfade bleiben |
| Team-Vertrautheit | Gut |

**Pro:** Schnell, risikoarm, beseitigt die konkret reachable Races (Welle 1).
**Contra:** 2×-Speicher und 2×-Audit-Log bleiben dauerhaft; jede Session-Feld-Erweiterung weiter an zwei Stellen.

### Option C — Studio ersetzt Copilot vollständig (Big-Bang-Rewrite)

WCS und `WorkflowManager` werden abgeschaltet, alles auf den Studio-Stack migriert.

| Dimension | Bewertung |
|---|---|
| Komplexität | Sehr hoch |
| Kosten | XXL |
| Skalierbarkeit | Sauberstes Endbild |
| Team-Vertrautheit | Riskant — Supervisor/Repair (7000+ Z.) hängen tief an WCS |

**Pro:** Endzustand ohne Altlast.
**Contra:** Höchstes Regressionsrisiko in genau dem Autonomie-Kern, der laut Protokoll ohnehin fragil ist; kein produktiver End-to-End-Lauf steht bisher (offene Verifikation seit Session 2).

---

## 4. Trade-off-Analyse

- **B ist der Einstieg, A das Ziel, C wird verworfen.** Die Guards aus Option B (Welle 1) sind ohnehin nötig und sind zugleich die ersten Schritte von Option A — es gibt keinen Wegwurf. C ist verworfen, weil es das Regressionsrisiko in den fragilsten Kern (Supervisor/Repair) legt, bevor überhaupt ein produktiver Live-Lauf existiert.
- **Reihenfolge = Risiko vor Kosmetik.** Der Audit-Log-Cascade (§5, kritisch) und die un-redigierte Ledger-Spalte (Regel 6) werden vor jeder Refactoring-Arbeit geschlossen, weil sie Daten/Compliance betreffen, nicht nur Wartbarkeit.
- **God-Object-Splits sind verhaltensneutral** und niedrigriskant, aber sie sind Voraussetzung dafür, dass die sicherheits-/regelkritische Logik (SSRF-Guard Regel-implizit, Success-Criteria Regel 4, Struktur-Ops Regeln 2/3) überhaupt isoliert testbar wird.
- **Alle Empfehlungen sind regelkonform** (verifiziert): keine zweite Vorschau (Regel 1), Karten weiter kataloggebunden (Regel 2), Events append-only (Regel 5), Exporte redigiert (Regel 6), `execution_target=system` bleibt.

---

## 5. Kritischer Einzelbefund (schema-seitige Regel-5-Verletzung)

`workflow_studio_events` (Migration `2026_07_17_210000:50`) und `workflow_studio_checkpoints` (`:80`) hängen per `cascadeOnDelete` an der Session; die Session hängt per `cascadeOnDelete` am Workflow (`:17`); das Copilot-Event-Pendant ist identisch (`2026_07_14_080000:43-45`). **Ein einziges `Workflow::delete()` löscht damit den kompletten, „unveränderlichen" Audit-Log hart** — obwohl Regel 5 genau das verbietet (App-Layer-Schutz existiert nur via `UPDATED_AT=null`). Inkonsistent obendrein: Revisionen nutzen `nullOnDelete` auf die Session, kaskadieren aber ebenfalls über `workflow_id` (`:64`).

**Fix (neue Migration, nicht die alte editieren):** Audit-Tabellen von `cascadeOnDelete` auf `nullable + nullOnDelete` umstellen und `workflow_id` + `session_uuid` auf die Event-/Checkpoint-Zeile denormalisieren, damit verwaiste Audit-Zeilen attribuierbar bleiben. Einheitliche „Audit-Zeilen kaskadieren nie"-Regel über alle vier Tabellen (events, checkpoints, revisions inkl. `workflow_id`-FK). — *Severity: hoch (Auslöser ist ein expliziter Delete, kein Normalbetrieb; aber irreversibler Datenverlust).*

---

## 6. Action Items — priorisierter Backlog

Legende Aufwand: **S** ≤0,5 Tag · **M** ~1–2 Tage · **L** ~3–5 Tage · **XL** >1 Woche/gestaffelt.
Alle Datei:Zeile-Anker sind verifiziert (relativ zu `AiUserFactory/`).

### Welle 0 — Governance (sofort)
1. [ ] Studio-Arbeit (`16e3fb0`…`82c3f5b`) als Sammel-Eintrag ins README-Arbeitsprotokoll nachtragen (Regel 8). Manager-vs-Studio-Verhältnis in der Architektur-Tabelle festhalten. **S**
2. [ ] Voice-Zeile der Soll-Ist-Tabelle auf „installiert/verifiziert" korrigieren (widerspricht dem eigenen Protokoll). **S**

### Welle 1 — Korrektheit / Integrität / Security (P0)
3. [ ] **Audit-Log-Cascade** → `nullOnDelete` + denormalisierte `workflow_id`/`session_uuid` (§5). Neue Migration. **M** · *hoch*
4. [ ] **`side_effect_ledger_json` redigieren:** `WorkflowStudioCheckpointService.php:79` durch `safeContext(...)` leiten (einzige Spalte, die weder verschlüsselt noch redigiert ist; Geschwister-Spalten sind es). Test mit gepflanztem Token → `[geschuetzt]`. **S** · *hoch*
5. [ ] **Single-Active-Studio-Session:** `latestOrOpen` (`WorkflowStudioSessionService.php:74-88`) in `DB::transaction` mit `Workflow::lockForUpdate()` reuse-or-create; direkten `open()`-Aufruf in `WorkflowsIndex.php:305` durchrouten; alte Drafts auf `stopped` schließen. Spiegelt den bestehenden Copilot-Workflow-Lock. **M** · *hoch*
6. [ ] **`attachRun`-Ownership-Guard** (`WorkflowStudioSessionService.php:90-103`): Lauf ablehnen/relinken, der bereits einer anderen WSS gehört (analog `WorkflowCopilotSessionService::attachRun`); WR→WSS über die FK statt `where('workflow_copilot_session_id')->latest('id')` (`WorkflowExecutionService.php:1745-1748`) auflösen. **M** · *hoch*
7. [ ] **Status-Wedge schließen** (verifiziert enger als ursprünglich gemeldet): `stopCopilot` (`WorkflowStudio.php:582-599`) und `discardPendingAction` (`:360-364`) räumen `state_json.pending_copilot_confirmation` + `context_json.studio_authorization_hold` nicht ab; `refreshStudio` (`:628-631`) rehydriert den veralteten Pending. Fix: bei terminalem/entpausiertem WR in `refreshStudio` Pending löschen und WSS.status synchronisieren, auch aus `confirmation_required`. **S** · *mittel*
8. [ ] **Vier Guardrail-Tests** (die Divergenz-Races haben null Regressionsschutz): (a) `confirmation_required`-Lauf terminiert → WSS entwedgt; (b) `latestOrOpen` liefert bei wiederholtem Aufruf eine Session; (c) `attachRun` „stiehlt" keinen fremden Lauf; (d) Checkpoint-`restore` bleibt konsistent/rollt atomar zurück. `Queue::fake()`, gemeinsam auf WSS.status+state_json, WR.status, WR.workflow_studio_session_id asserten. **M** · *mittel*

### Welle 2 — Performance-Quick-Wins (P1, alle S/M)
9. [ ] **Resolver memoisieren:** `workflow()`/`session()`/`activeRun()` (`WorkflowStudio.php:757-770`) request-scoped cachen (nullable Property oder `#[Computed]`). Behebt ~15–20 redundante SELECTs/2s-Poll pro Tab. **M** · *hoch*
10. [ ] **`Person`/`NetworkNode`-Listen** (`:668-669`) aus dem Poll nehmen: nur bei `$activeStudioPanel === 'copilot'` laden (500 `Person`-Zeilen werden aktuell alle 2s hydriert). **S** · *hoch*
11. [ ] **Toten Event-Eager-Load** entfernen: `events` aus `->load([...])` bei `:648` streichen (der View nutzt die bounded Query `:665`; die volle append-only-Liste wächst unbegrenzt). Copilot-Pfad `:661` nicht anfassen. **S** · *mittel*
12. [ ] **Poll-Kadenz zustandsabhängig:** `wire:poll` auf 2s nur bei aktiv/pausiert, sonst 15s / weglassen bei final. Run-State-Berechnung dafür über den `wire:poll`-Knoten ziehen (aktuell erst `:26-28`, nach der Attribut-Zeile `:22`). **S** · *mittel*
13. [ ] **Doppelten Steps-Load** entschärfen: `workflow()` lädt `with('steps')`, `render():647` lädt `steps` erneut → auf `->load(['studioRevisions'])` reduzieren. **S** · *niedrig*
14. [ ] **Composite-Index** `(workflow_studio_session_id, revision_number)` nachziehen (fehlt ggü. Copilot-Zwilling `workflow_revisions_session_number_idx`). Neue Migration, rein additiv. **S** · *niedrig*

### Welle 3 — Entkopplung & Dekomposition (P2)
15. [ ] **DI statt Service-Locator:** Livewire-`boot(...)` mit typisierten Properties für alle **8** Services (die 6 gemeldeten + `WorkflowTaskOrderingService`, `WorkflowTaskCatalog`, `WorkflowDefinitionValidator`), 32 `app(...)`-Stellen ersetzen. Mechanisch, testbar. **M** · *mittel*
16. [ ] **`WorkflowStudioOrchestrator` extrahieren:** WSS/WCS/WR-Transitionsregeln + Job-Dispatch aus `confirmPendingAction`/`refreshStudio`/`start/restart/stop/terminateCopilot` in einen Service; **alle** Status-Schreiber über ein `reconcileStatus` routen (ein Besitzer für `confirmation_required`-Clearing). **L** · *hoch*
17. [ ] **God-Object-Cluster herauslösen** (verhaltensneutral, jeder mit eigenem Test):
    - `WorkflowSuccessCriteriaEvaluator` aus Supervisor (L2124-2406, ~297 Z.; macht den Regel-4-Gate isoliert testbar; `canonicalValue` als Shared-Helper belassen). **M** · *hoch*
    - `WorkflowCopilotDomainTrustPolicy` + `SelectorSafetyPolicy` aus Repair (SSRF-/Host-Trust, L1729-2045; `isSafeNetworkHost` macht DNS → Test muss DNS gaten; `trustedWorkflowDomains` bleibt DB-nah). **M** · *hoch*
    - `WorkflowConsentObstacleResolver` aus Repair (~13 Methoden, Karten weiter via Katalog, Regel 2). **L** · *mittel*
    - `WorkflowCopilotBudgetPolicy` + `WorkflowCopilotCheckpointLedger` aus Supervisor (Regel-10-Semantik `max_cost_usd=0=unbegrenzt` wörtlich erhalten). **M** · *mittel*
    - `WorkflowStructuralOperationNormalizer` aus Repair (354-Z.-Methode `normalizeStructuralOperations`, per-Op-Typ-Dispatch; Regeln 2/3). **M** · *mittel*
18. [ ] **Verwaisten Studio-Checkpoint-Stack entscheiden & entfernen** (default: löschen): nach `cacfea3` ist `WorkflowStudioCheckpointService` nur noch im Test referenziert; die UI liest den älteren `WorkflowRunCheckpoint` (`WorkflowStudio.php:666`). Entfernen: Service, Modell, `WorkflowStudioSession::checkpoints()` (57-60), `checkpoint.restore/branch` aus `CRITICAL_ACTIONS`, `workflow_studio_checkpoints`-Tabelle (down-Migration), zugehöriger Test. **L** · *mittel*
19. [ ] **Run-Linkage single-source:** FK-Spalten (`workflow_copilot_session_id`, `workflow_studio_session_id`) als einzige Wahrheit; `context_json`-Kopien nur noch als Read-Mirror. Sequenz: Backfill-Migration (FK aus `context_json` wo NULL) → dann die `FK ?: context_json`-Fallbacks in `WorkflowExecutionService` (`:246-249`, `:262-268`, `:1727`, `:1740`) entfernen. Node-Runtime liest diese IDs nicht → Regel 7 nicht berührt. **L** · *mittel*

### Welle 4 — Struktur-Konsolidierung (P3, XL — Option A)
20. [ ] **Session-Doppelung auflösen** (§3 Option A): `execution_target` als echte Spalte (mit WCS-Guard), Permission/Budget-Spiegel über **einen** Setter, WCS auf autonom-spezifische Felder reduzieren, WSS als kanonischer UI-Aggregat. FK-Brücke bleibt. Standalone-Studio-Sessions (copilot-FK null) nicht unter WCS reparenten. **XL** · *hoch*
21. [ ] **Event-Log single-sourcen:** keine physische Zweitkopie mehr; Twin-Timeline über gespeicherte Quell-Event-ID oder Read-View/`UNION` auf `workflow_copilot_session_id` rekonstruieren. Bereits migrierte historische Zeilen nicht löschen (Regel 5). **XL** · *mittel*

---

## 7. Konsequenzen

**Leichter:** Session-Features nur noch an einem Ort; die Divergenz-Bug-Klasse (Stillstand/Wedge/verwaiste Läufe) verschwindet strukturell statt per Einzel-Patch; sicherheits-/regelkritische Logik (SSRF-Guard, Success-Criteria, Struktur-Ops, Budget) wird isoliert testbar; ~15–20 SELECTs/Poll/Tab und die 500-Zeilen-Hydrierung entfallen; der Audit-Log ist gegen versehentliche Löschung geschützt.

**Schwerer / zu beobachten:** Welle 4 fasst den fragilsten Autonomie-Kern an — muss gestaffelt mit erhaltener FK-Brücke laufen und braucht die Guardrail-Tests aus Welle 1 als Netz. Die God-Object-Splits ziehen geteilte Helper mit (keine reinen Lift-and-shifts) — Kollaboratoren injizieren, nicht duplizieren.

**Später neu zu bewerten:** Sobald ein **produktiver End-to-End-Lauf von Workflow 15 gegen echtes OpenRouter + sichtbaren Browser** existiert (die seit Session 2 offene Verifikation) — erst danach ist Option C überhaupt seriös diskutierbar.

---

## Anhang — Widerlegte / zurückgestufte Befunde

Fünf gemeldete Befunde hielten der Verifikation **nicht** stand und sind **nicht** im Backlog (Doppelzählung/Fehlattribution vermeiden):

- **TaskEditor dupliziert `addTaskCard`** — falsch: `WorkflowStudioTaskEditor::saveEditTaskCard` überschreibt das *parent* `WorkflowManager::saveEditTaskCard` (L962-1120) nahezu wortgleich; die „Drift"-Beispiele (`unset(on_partial)`, `input_selector=""`) sind identische Kopien der Parent-Edit-Methode, keine Divergenz.
- **Zwei Revision-Services teilen einen Zähler** — Kernidee real, aber mehrere Zitate falsch (u.a. `WorkflowCopilotSessionService.php:523` ist ein direkter Model-Write, kein Service-Call); als eigenständiger Backlog-Punkt zu unpräzise, geht in #20/#21 auf.
- **Kein Interface über `WorkflowExecutionService`** — Rückgabe-Contract ist `['ok'=>bool,'message'=>string]`, Studio leitet `status` aus `$run->status` ab, nicht aus der Response; die gemeldete Signatur-Kopplung existiert so nicht.
- **`WSS.status` wedged an zwei Schreibern** — die genannten Trigger (`stopRun`/`terminateRun`/`terminateCopilot`) *recovern* tatsächlich; der echte, engere Wedge ist als #7 aufgenommen.

*(Erzeugt aus verifiziertem Audit; jedes übernommene Item trägt geprüfte Datei:Zeile-Evidenz.)*
