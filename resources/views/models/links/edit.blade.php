@extends('layouts.app')

@section('content')

    <div class="card">
        <header class="card-header">
            @lang('link.edit')
        </header>
        <div class="card-body">

            <form action="{{ route('links.update', [$link->id]) }}" method="POST" id="link-edit"
                class="edit-form link-form">
                @method('PATCH')
                @csrf

                <input type="hidden" name="link_id" value="{{ $link->id }}">

                <div class="mb-4">
                    <label class="form-label" for="url">@lang('link.url')</label>
                    <input name="url" id="url" type="url" data-ignore-id="{{ $link->id }}"
                        class="form-control form-control-lg{{ $errors->has('url') ? ' is-invalid' : '' }}"
                        placeholder="@lang('placeholder.link_url')" value="{{ old('url') ?: $link->url }}"
                        required autofocus>

                    <div class="invalid-feedback link-exists d-flex align-items-center gap-2 {{ $existing_link ? '' : 'd-none' }}">
                        <div>@lang('link.existing_found')</div>
                        <a href="{{ route('links.edit', [$existing_link->id ?? 0]) }}"
                            class="link-exists-edit {{ $existing_link && $existing_deleted ? 'd-none' : '' }}">@lang('link.edit')</a>

                        <button type="submit" form="exiting-link-restore" title="@lang('trash.restore')"
                            class="link-exists-restore btn btn-sm btn-link {{ $existing_link && $existing_deleted ? '' : 'd-none' }}" >
                            @lang('trash.restore_deleted_link')
                        </button>
                    </div>

                    @if ($errors->has('url'))
                        <p class="invalid-feedback" role="alert">
                            {{ $errors->first('url') }}
                        </p>
                    @endif
                </div>

                <div class="row">
                    <div class="col-12 col-sm-6 col-md-7">

                        <div class="mb-4">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="form-label mb-0" for="title">@lang('link.title')</label>
                                <button type="button" class="btn btn-xs btn-outline-secondary link-meta-fetch"
                                    data-url-target="#url"
                                    data-title-target="#title"
                                    data-description-target="#description"
                                    data-status-target="#link-meta-status"
                                    data-loading-text="@lang('link.fetch_meta_loading')"
                                    data-loading-label="@lang('link.fetch_meta_loading')"
                                    data-success-text="@lang('link.fetch_meta_success')"
                                    data-error-text="@lang('link.fetch_meta_error')"
                                    data-missing-url-text="@lang('link.fetch_meta_missing_url')">
                                    @lang('link.fetch_meta')
                                </button>
                            </div>
                            <div id="link-meta-status" class="form-text d-none"></div>
                            <input name="title" id="title"
                                class="form-control{{ $errors->has('title') ? ' is-invalid' : '' }}"
                                type="text" placeholder="@lang('placeholder.link_url')"
                                value="{{ old('title') ?: $link->title }}">
                            @if ($errors->has('title'))
                                <p class="invalid-feedback" role="alert">
                                    {{ $errors->first('title') }}
                                </p>
                            @endif
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="description">@lang('link.description')</label>
                            <textarea name="description" id="description" rows="4" class="form-control"
                            >{{ old('description') ?: $link->description }}</textarea>

                            @if ($errors->has('description'))
                                <p class="invalid-feedback" role="alert">
                                    {{ $errors->first('description') }}
                                </p>
                            @endif
                        </div>

                    </div>
                    <div class="col-12 col-sm-6 col-md-5">

                        <div class="mb-4">
                            <label class="form-label" for="lists">@lang('list.lists')</label>
                            <input name="lists" id="lists" type="text" placeholder="@lang('placeholder.list_select')"
                                class="tag-select" data-tag-data="{{ $all_lists->toJson() }}"
                                data-value="{{ $link->oldTaxonomyOutput('lists') }}"
                                data-allow-creation="1" data-tag-type="lists">

                            @if ($errors->has('lists'))
                                <p class="invalid-feedback" role="alert">
                                    {{ $errors->first('lists') }}
                                </p>
                            @endif
                        </div>

                        <div class="mb-4">
                            <label class="form-label" for="tags">@lang('tag.tags')</label>
                            <input name="tags" id="tags" type="text" placeholder="@lang('placeholder.tags_select')"
                                class="tag-select" data-tag-data="{{ $all_tags->toJson() }}"
                                data-value="{{ $link->oldTaxonomyOutput('tags') }}"
                                data-allow-creation="1" data-tag-type="tags">

                            @if ($errors->has('tags'))
                                <p class="invalid-feedback" role="alert">
                                    {{ $errors->first('tags') }}
                                </p>
                            @endif
                        </div>

                        <x-forms.visibility-toggle class="mb-4" :existing-value="$link->visibility"/>

                    </div>
                </div>

                <div class="mt-3 d-sm-flex flex-wrap align-items-center">

                    <div class="d-sm-inline-block mb-3 mb-sm-0 me-auto">
                        <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick="window.deleteLink.submit()">
                            <x-icon.trash class="me-2"/> @choice('link.delete', 1)
                        </button>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <x-icon.save class="me-2"/> @lang('link.update')
                    </button>

                </div>

            </form>

        </div>
    </div>

    <form action="{{ route('links.destroy', [$link->id]) }}" method="post" id="deleteLink">
        @csrf
        @method('DELETE')
    </form>

    <form action="{{ route('trash-restore') }}" method="post" id="exiting-link-restore">
        @csrf
        <input type="hidden" name="model" value="link">
        <input type="hidden" name="redirect_to_model" value="1">
        <input type="hidden" name="id" class="link-exists-restore-id" value="{{ $existing_link->id ?? 0 }}">
    </form>

@endsection
