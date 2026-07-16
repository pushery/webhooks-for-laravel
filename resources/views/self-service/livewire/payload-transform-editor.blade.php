{{-- The structured payload-transform editor for one endpoint. The tenant builds the
     declarative rules from typed controls — include / exclude / rename / rewrap — and
     picks a payload version; a sample payload is run through the exact delivery-time
     transformer on every change, so the two code panes show the real before/after body.
     Owner-scoped and policy-authorized, WireKit-tokenized throughout. --}}
<div class="wh-portal wh-portal-transform mx-auto flex max-w-5xl flex-col gap-[var(--padding-wk-y-lg)] p-[var(--padding-wk-x-lg)]" wire:key="transform-editor">
    <header class="flex flex-wrap items-start justify-between gap-[var(--padding-wk-x-md)]">
        <x-wirekit::stack gap="sm">
            <x-wirekit::heading :level="1" size="lg">{{ __('webhooks::self-service.transform.heading') }}</x-wirekit::heading>
            @if ($this->endpointUrl !== null)
                <x-wirekit::text variant="muted" class="break-all">{{ $this->endpointUrl }}</x-wirekit::text>
            @endif
        </x-wirekit::stack>
        <x-wirekit::button :href="route('webhooks.self-service')" wire:navigate size="sm" surface="ghost" intent="neutral">
            {{ __('webhooks::self-service.actions.back_to_endpoints') }}
        </x-wirekit::button>
    </header>

    @unless ($versioningEnabled)
        <x-wirekit::callout variant="warning">
            {{ __('webhooks::self-service.transform.versioning_disabled') }}
        </x-wirekit::callout>
    @endunless

    <div class="grid grid-cols-1 gap-[var(--padding-wk-y-lg)] lg:grid-cols-2">
        {{-- Rule builder --}}
        <x-wirekit::card>
            <x-wirekit::card.body>
                <x-wirekit::stack gap="lg">
                    <x-wirekit::heading :level="2" size="sm">{{ __('webhooks::self-service.transform.rules') }}</x-wirekit::heading>

                    <x-wirekit::select
                        :label="__('webhooks::self-service.transform.version_label')"
                        :hint="__('webhooks::self-service.transform.version_hint')"
                        wire:model.live="payloadVersion"
                        :options="$versionOptions"
                    />

                    <x-wirekit::field
                        :label="__('webhooks::self-service.transform.include_label')"
                        :hint="__('webhooks::self-service.transform.include_hint')"
                    >
                        <x-wirekit::stack gap="xs">
                            @foreach ($includeFields as $i => $field)
                                <div class="flex items-center gap-[var(--gap-wk-sm)]" wire:key="include-{{ $i }}">
                                    <div class="flex-1">
                                        <x-wirekit::input
                                            wire:model.live.debounce.400ms="includeFields.{{ $i }}"
                                            :placeholder="__('webhooks::self-service.transform.field_name_placeholder')"
                                            :aria-label="__('webhooks::self-service.a11y.include_field', ['number' => $i + 1])"
                                        />
                                    </div>
                                    <x-wirekit::button
                                        type="button"
                                        size="sm"
                                        surface="ghost"
                                        intent="danger"
                                        wire:click="removeIncludeField({{ $i }})"
                                        :aria-label="__('webhooks::self-service.a11y.remove_include_field', ['number' => $i + 1])"
                                    >{{ __('webhooks::self-service.actions.remove') }}</x-wirekit::button>
                                </div>
                            @endforeach
                            <div>
                                <x-wirekit::button type="button" size="sm" surface="ghost" wire:click="addIncludeField">{{ __('webhooks::self-service.transform.add_include') }}</x-wirekit::button>
                            </div>
                        </x-wirekit::stack>
                    </x-wirekit::field>

                    <x-wirekit::field
                        :label="__('webhooks::self-service.transform.exclude_label')"
                        :hint="__('webhooks::self-service.transform.exclude_hint')"
                    >
                        <x-wirekit::stack gap="xs">
                            @foreach ($excludeFields as $i => $field)
                                <div class="flex items-center gap-[var(--gap-wk-sm)]" wire:key="exclude-{{ $i }}">
                                    <div class="flex-1">
                                        <x-wirekit::input
                                            wire:model.live.debounce.400ms="excludeFields.{{ $i }}"
                                            :placeholder="__('webhooks::self-service.transform.field_name_placeholder')"
                                            :aria-label="__('webhooks::self-service.a11y.exclude_field', ['number' => $i + 1])"
                                        />
                                    </div>
                                    <x-wirekit::button
                                        type="button"
                                        size="sm"
                                        surface="ghost"
                                        intent="danger"
                                        wire:click="removeExcludeField({{ $i }})"
                                        :aria-label="__('webhooks::self-service.a11y.remove_exclude_field', ['number' => $i + 1])"
                                    >{{ __('webhooks::self-service.actions.remove') }}</x-wirekit::button>
                                </div>
                            @endforeach
                            <div>
                                <x-wirekit::button type="button" size="sm" surface="ghost" wire:click="addExcludeField">{{ __('webhooks::self-service.transform.add_exclude') }}</x-wirekit::button>
                            </div>
                        </x-wirekit::stack>
                    </x-wirekit::field>

                    <x-wirekit::field
                        :label="__('webhooks::self-service.transform.rename_label')"
                        :hint="__('webhooks::self-service.transform.rename_hint')"
                    >
                        <x-wirekit::stack gap="xs">
                            @foreach ($renamePairs as $i => $pair)
                                <div class="flex items-center gap-[var(--gap-wk-sm)]" wire:key="rename-{{ $i }}">
                                    <div class="flex-1">
                                        <x-wirekit::input
                                            wire:model.live.debounce.400ms="renamePairs.{{ $i }}.from"
                                            :placeholder="__('webhooks::self-service.transform.rename_from_placeholder')"
                                            :aria-label="__('webhooks::self-service.a11y.rename_source_field', ['number' => $i + 1])"
                                        />
                                    </div>
                                    <span class="text-[color:var(--color-wk-text-muted)]" aria-hidden="true">&rarr;</span>
                                    <div class="flex-1">
                                        <x-wirekit::input
                                            wire:model.live.debounce.400ms="renamePairs.{{ $i }}.to"
                                            :placeholder="__('webhooks::self-service.transform.rename_to_placeholder')"
                                            :aria-label="__('webhooks::self-service.a11y.rename_target_field', ['number' => $i + 1])"
                                        />
                                    </div>
                                    <x-wirekit::button
                                        type="button"
                                        size="sm"
                                        surface="ghost"
                                        intent="danger"
                                        wire:click="removeRenamePair({{ $i }})"
                                        :aria-label="__('webhooks::self-service.a11y.remove_rename_pair', ['number' => $i + 1])"
                                    >{{ __('webhooks::self-service.actions.remove') }}</x-wirekit::button>
                                </div>
                            @endforeach
                            <div>
                                <x-wirekit::button type="button" size="sm" surface="ghost" wire:click="addRenamePair">{{ __('webhooks::self-service.transform.add_rename') }}</x-wirekit::button>
                            </div>
                        </x-wirekit::stack>
                    </x-wirekit::field>

                    <x-wirekit::input
                        :label="__('webhooks::self-service.transform.rewrap_label')"
                        :hint="__('webhooks::self-service.transform.rewrap_hint')"
                        wire:model.live.debounce.400ms="rewrapKey"
                        :placeholder="__('webhooks::self-service.transform.rewrap_placeholder')"
                    />

                    <div>
                        <x-wirekit::button wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ __('webhooks::self-service.transform.save') }}</x-wirekit::button>
                    </div>
                </x-wirekit::stack>
            </x-wirekit::card.body>
        </x-wirekit::card>

        {{-- Live preview --}}
        <x-wirekit::card>
            <x-wirekit::card.body>
                <x-wirekit::stack gap="md">
                    <x-wirekit::heading :level="2" size="sm">{{ __('webhooks::self-service.transform.preview_heading') }}</x-wirekit::heading>

                    {{-- Malformed JSON is named, never swallowed: without this the sample simply
                         previews as {} and the tenant reads it as "my rules broke the payload". --}}
                    <x-wirekit::textarea
                        :label="__('webhooks::self-service.transform.sample_label')"
                        :hint="__('webhooks::self-service.transform.sample_hint')"
                        wire:model.live.debounce.400ms="sampleJson"
                        rows="8"
                        :error="$this->sampleError()"
                    />

                    <div class="grid grid-cols-1 gap-[var(--padding-wk-x-md)] md:grid-cols-2">
                        <x-wirekit::stack gap="xs">
                            <x-wirekit::text size="sm" weight="medium">{{ __('webhooks::self-service.transform.input') }}</x-wirekit::text>
                            <x-wirekit::code-block language="json" :copy="true" class="wh-transform-input">{{ $inputJson }}</x-wirekit::code-block>
                        </x-wirekit::stack>
                        {{-- A labelled region, NOT a live region around the code block: the output
                             body recomputes on every debounced keystroke, and a live region there
                             would read the whole JSON document out again every 400 ms. The short
                             status sentence beside it carries the announcement instead. --}}
                        <div role="region" aria-label="{{ __('webhooks::self-service.a11y.output_preview') }}">
                            <x-wirekit::stack gap="xs">
                                <x-wirekit::text size="sm" weight="medium">{{ __('webhooks::self-service.transform.output') }}</x-wirekit::text>
                                <x-wirekit::visually-hidden role="status" aria-live="polite" wire:key="wh-transform-preview-{{ $previewRevision }}">{{ __('webhooks::self-service.a11y.output_updated') }}</x-wirekit::visually-hidden>
                                <x-wirekit::code-block language="json" :copy="true" class="wh-transform-output">{{ $outputJson }}</x-wirekit::code-block>
                            </x-wirekit::stack>
                        </div>
                    </div>
                </x-wirekit::stack>
            </x-wirekit::card.body>
        </x-wirekit::card>
    </div>

    <x-wirekit::toast-region />
</div>
