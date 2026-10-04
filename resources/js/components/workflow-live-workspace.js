/** Presentation of one authoritative Studio run; never starts or controls work. */
export function workflowLiveWorkspace(config = {}, routeSurface = {}) {
    return {
        ...routeSurface,
        workspaceRunId: Number(config.runId || 0),
        workspaceRunPresentation: String(config.presentation || 'edit'),
        desktopSidebar: true,
        mobileLibraryOpen: false,
        libraryExpanded: true,
        resultEditing: false,
        _libraryBeforeLive: null,
        _lastFollowedCursor: '',

        init() {
            routeSurface.init?.call(this);
            this.desktopSidebar = window.matchMedia('(min-width: 720px)').matches;
            this.syncWorkspaceRun();
            // Read only the child properties hydrated from its own session.
            // Event payloads may be late or belong to a different Studio.
            this.$wire.$watch('workspaceRunId', () => this.syncWorkspaceRun());
            this.$wire.$watch('workspaceRunPresentation', () => this.syncWorkspaceRun());
            this.$wire.$watch('workspaceCursorNode', () => this.$nextTick(() => this.followLiveCursor()));
        },

        destroy() {
            routeSurface.destroy?.call(this);
        },

        syncWorkspaceRun() {
            this.applyWorkspaceRun(
                Number(this.$wire?.workspaceRunId || 0),
                String(this.$wire?.workspaceRunPresentation || 'edit'),
            );
        },

        applyWorkspaceRun(runId, presentation) {
            const previous = this.workspaceRunPresentation;
            const changedRun = this.workspaceRunId !== Number(runId || 0);
            const next = ['edit', 'live', 'result'].includes(presentation) ? presentation : 'edit';
            this.workspaceRunId = Number(runId || 0);
            this.workspaceRunPresentation = next;

            if (changedRun || previous !== next) this.resultEditing = false;
            if (changedRun || next !== 'live') this._lastFollowedCursor = '';
            if (next === 'live') {
                if (this._libraryBeforeLive === null) {
                    this._libraryBeforeLive = {
                        expanded: this.libraryExpanded,
                        mobile: this.mobileLibraryOpen,
                    };
                }
                this.libraryExpanded = false;
                this.mobileLibraryOpen = false;
            } else if (previous === 'live' && this._libraryBeforeLive !== null) {
                this.libraryExpanded = this._libraryBeforeLive.expanded;
                this.mobileLibraryOpen = this._libraryBeforeLive.mobile;
                this._libraryBeforeLive = null;
            }

            this.$nextTick(() => {
                this.queueRouteRefresh?.();
                this.$dispatch('workflow-minimap-refresh-requested', {
                    instance: String(config.previewInstance || ''),
                });
                this.followLiveCursor();
            });
        },

        followLiveCursor() {
            const cursor = String(this.$wire?.workspaceCursorNode || '');
            if (!this.isLiveRun() || !cursor || cursor === this._lastFollowedCursor) return;
            const preview = this.$root?.querySelector('[data-workflow-live-preview]');
            const target = Array.from(preview?.querySelectorAll('[data-minimap-node]') || [])
                .find((node) => node.dataset.minimapNode === cursor);
            if (!target || target.offsetParent === null) return;
            // Follow a new observed cursor once, not every unchanged poll. Keep
            // keyboard focus and inspected task selection owned by the user.
            target.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'nearest' });
            this._lastFollowedCursor = cursor;
        },

        isLiveRun() {
            return this.workspaceRunPresentation === 'live';
        },

        showLivePreview() {
            return this.isLiveRun() || (this.workspaceRunPresentation === 'result' && !this.resultEditing);
        },

        isLibraryVisible() {
            if (this.isLiveRun() || this.showLivePreview()) return false;
            return this.desktopSidebar ? this.libraryExpanded : this.mobileLibraryOpen;
        },

        setLibraryExpanded(expanded, focusToggle = false) {
            if (this.isLiveRun()) return;
            if (expanded && this.workspaceRunPresentation === 'result') this.resultEditing = true;
            if (this.desktopSidebar) this.libraryExpanded = Boolean(expanded);
            else this.mobileLibraryOpen = Boolean(expanded);
            this.$nextTick(() => {
                this.queueRouteRefresh?.();
                if (focusToggle) {
                    const target = this.isLibraryVisible() ? this.$refs.libraryCollapseButton : this.$refs.libraryOpenButton;
                    target?.focus({ preventScroll: true });
                }
            });
        },

        toggleLibrary() {
            this.setLibraryExpanded(!this.isLibraryVisible(), true);
        },

        editRunResult() {
            if (this.isLiveRun()) return;
            this.resultEditing = true;
            this.$nextTick(() => this.queueRouteRefresh?.());
        },

        enterDefinitionWorkbench(detail = {}) {
            if (!this.eventTargetsThisEditor(detail) || this.isLiveRun()) return;
            this.desktopSidebar = window.matchMedia('(min-width: 720px)').matches;
            this.resultEditing = this.workspaceRunPresentation === 'result';
            this.libraryExpanded = true;
            this.mobileLibraryOpen = false;
            this.$nextTick(() => this.queueRouteRefresh?.());
        },
    };
}
