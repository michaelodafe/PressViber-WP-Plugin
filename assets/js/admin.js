/**
 * PressViber – Admin UI  v0.3.0
 * Vanilla JS, no build step required.
 *
 * Architecture:
 *   STATE      – view management (landing ↔ chat)
 *   SELECTS    – custom glass dropdown components
 *   PAGES      – fetch WP pages via AJAX into page selector
 *   CHIPS      – quick template chips on landing
 *   LANDING    – submit from landing → transition to chat
 *   CHAT       – multi-turn conversation loop
 *   AGENT      – SSE stream from pv_agent_run
 *   MESSAGES   – render user / assistant / tool-call messages
 *   PREVIEW    – iframe management
 *   MARKDOWN   – lightweight md → HTML
 *   TOAST      – bottom notification
 */

( function () {
    'use strict';

    /* =========================================================================
       STATE
       ========================================================================= */

    var state = {
        view:       'landing',   // 'landing' | 'chat'
        history:    [],          // [{role, content}] for multi-turn
        pageId:     '',
        pageUrl:    '',
        pageTitle:  '',
        targetKind: '',
        previewUrl: '',
        previewPoll: null,
        previewTimeout: null,
        previewRequestId: 0,
        model:      'gpt5_4',
        modelLabel: 'GPT-5.4',
        busy:       false,
        streamCtrl: null,        // AbortController for active SSE stream
        editorMode: false,
        returnUrl:  '',
        previewDevice: 'desktop',
    };

    /* =========================================================================
       TOAST
       ========================================================================= */

    function toast( msg, ms ) {
        ms = ms || 3500;
        var el = document.getElementById( 'pv-toast' );
        if ( ! el ) return;
        el.textContent = msg;
        el.classList.add( 'pv-toast--visible' );
        clearTimeout( el._t );
        el._t = setTimeout( function () { el.classList.remove( 'pv-toast--visible' ); }, ms );
    }

    /* =========================================================================
       UTILS
       ========================================================================= */

    var esc_map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    function esc( s ) { return String( s ).replace( /[&<>"']/g, function(c) { return esc_map[c]; } ); }

    function scrollBottom( el ) {
        if ( el ) el.scrollTop = el.scrollHeight;
    }

    function homeUrl() {
        return ( window.pvData && ( pvData.homeUrl || pvData.siteUrl ) ) ? ( pvData.homeUrl || pvData.siteUrl ) : window.location.origin + '/';
    }

    function getInitialContext() {
        return ( window.pvData && pvData.initialContext ) ? pvData.initialContext : {};
    }

    function stripTrailingJunk( value ) {
        return String( value || '' ).replace( /[).,;!?'"`]+$/, '' );
    }

    function normalizePreviewUrl( value ) {
        value = String( value || '' ).trim();
        if ( ! value || value === 'about:blank' ) return '';

        try {
            return new URL( value, homeUrl() ).toString();
        } catch ( e ) {
            return '';
        }
    }

    function humanizeSlug( slug ) {
        return String( slug || '' )
            .replace( /^\/+|\/+$/g, '' )
            .split( '/' )
            .filter( Boolean )
            .pop()
            .replace( /[-_]+/g, ' ' )
            .replace( /\b\w/g, function( chr ) { return chr.toUpperCase(); } ) || 'Homepage';
    }

    function deriveTitleFromUrl( url ) {
        var normalized = normalizePreviewUrl( url );
        if ( ! normalized ) return 'Homepage';

        try {
            var parsed = new URL( normalized );
            var path   = parsed.pathname.replace( /^\/+|\/+$/g, '' );
            return path ? humanizeSlug( path ) : 'Homepage';
        } catch ( e ) {
            return 'Homepage';
        }
    }

    function extractTargetFromText( text ) {
        text = String( text || '' );
        if ( ! text.trim() ) return null;

        var fullUrl = text.match( /https?:\/\/[^\s<>"']+/i );
        if ( fullUrl ) {
            var absolute = normalizePreviewUrl( stripTrailingJunk( fullUrl[0] ) );
            if ( absolute ) {
                return { url: absolute, title: deriveTitleFromUrl( absolute ), explicit: true };
            }
        }

        var pathMatch = text.match( /(?:^|[\s(])((?:\/[a-z0-9][a-z0-9-]*)+\/?)(?=[\s).,;!?]|$)/i );
        if ( pathMatch ) {
            var absolutePath = normalizePreviewUrl( stripTrailingJunk( pathMatch[1] ) );
            if ( absolutePath ) {
                return { url: absolutePath, title: deriveTitleFromUrl( absolutePath ), explicit: true };
            }
        }

        var slugMatch = text.match( /\b([a-z0-9]+(?:-[a-z0-9]+)+)\s+(?:page|route|screen)\b/i );
        if ( slugMatch ) {
            var inferred = normalizePreviewUrl( '/' + slugMatch[1] + '/' );
            if ( inferred ) {
                return { url: inferred, title: humanizeSlug( slugMatch[1] ), explicit: false };
            }
        }

        return null;
    }

    function updateChatContext() {
        var badge = document.getElementById( 'pv-chat-page-name' );
        var title = state.pageTitle || deriveTitleFromUrl( state.pageUrl );
        if ( badge ) {
            badge.textContent = title || 'Homepage';
            badge.title = state.pageUrl || '';
        }

        updateExitLink();
        updateChatPlaceholder();
    }

    function setActiveTarget( url, title ) {
        url = normalizePreviewUrl( url );
        if ( ! url ) return false;

        state.pageUrl    = url;
        state.previewUrl = url;
        state.pageTitle  = title || deriveTitleFromUrl( url );
        updateChatContext();
        updatePreviewMeta( url );
        return true;
    }

    function updateExitLink() {
        var link = document.getElementById( 'pv-exit-editor' );
        if ( ! link ) return;

        link.href = normalizePreviewUrl( state.returnUrl || state.pageUrl || homeUrl() ) || homeUrl();
        link.hidden = ! state.editorMode;
    }

    function updateChatPlaceholder() {
        var input = document.getElementById( 'pv-chat-input' );
        if ( ! input ) return;

        input.placeholder = state.editorMode
            ? 'Describe what to build, fix, or improve on this page… (Ctrl+Enter to send)'
            : 'Continue the conversation… (Ctrl+Enter to send)';
    }

    function clearChatSession() {
        state.history = [];

        var messages = document.getElementById( 'pv-chat-messages' );
        if ( messages ) messages.innerHTML = '';

        var input = document.getElementById( 'pv-chat-input' );
        if ( input ) {
            input.value = '';
            growTextarea( input );
        }
    }

    function updateSelectVisual( wrap, value, label ) {
        if ( ! wrap ) return;

        var labelEl = wrap.querySelector( '.pv-select__label' );
        if ( labelEl ) labelEl.textContent = label;

        wrap.querySelectorAll( '.pv-select__option' ).forEach( function(o) {
            var active = o.dataset.value === value;
            o.classList.toggle( 'pv-select__option--active', active );
            o.setAttribute( 'aria-selected', active ? 'true' : 'false' );
            var chk = o.querySelector( '.pv-select__check' );
            if ( chk ) chk.hidden = ! active;
        } );
    }

    function setModelChoice( value, label ) {
        state.model = value || 'gpt5_4';
        state.modelLabel = label || 'GPT-5.4';

        var hidden = document.getElementById( 'pv-model-value' );
        if ( hidden ) hidden.value = state.model;

        updateSelectVisual( document.getElementById( 'pv-model-select' ), state.model, state.modelLabel );
        updateSelectVisual( document.getElementById( 'pv-chat-model-select' ), state.model, state.modelLabel );
    }

    function initModelSync() {
        [ 'pv-model-select', 'pv-chat-model-select' ].forEach( function(id) {
            var wrap = document.getElementById( id );
            if ( ! wrap ) return;

            wrap.addEventListener( 'pv:change', function(e) {
                setModelChoice( e.detail.value, e.detail.label );
            } );
        } );

        var hidden = document.getElementById( 'pv-model-value' );
        var initialValue = hidden ? hidden.value : state.model;
        var active = document.querySelector( '#pv-model-select .pv-select__option--active span' ) ||
            document.querySelector( '#pv-chat-model-select .pv-select__option--active span' );

        setModelChoice( initialValue || 'gpt5_4', active ? active.textContent.trim() : state.modelLabel );
    }

    function updatePreviewDevice() {
        var viewport = document.getElementById( 'pv-preview-viewport' );
        var desktop  = document.getElementById( 'pv-preview-desktop' );
        var mobile   = document.getElementById( 'pv-preview-mobile' );

        if ( viewport ) {
            viewport.classList.toggle( 'pv-preview-viewport--desktop', state.previewDevice === 'desktop' );
            viewport.classList.toggle( 'pv-preview-viewport--mobile', state.previewDevice === 'mobile' );
        }

        [ [ desktop, 'desktop' ], [ mobile, 'mobile' ] ].forEach( function(entry) {
            var button = entry[0];
            var mode   = entry[1];
            if ( ! button ) return;
            var active = state.previewDevice === mode;
            button.classList.toggle( 'is-active', active );
            button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
        } );
    }

    function syncTargetFromText( text, options ) {
        options = options || {};

        var target = extractTargetFromText( text );
        if ( ! target ) return false;

        if ( false === options.allowImplicit && ! target.explicit ) return false;
        if ( options.onlyWhenEmpty && state.pageUrl ) return false;
        if ( ! options.overrideSelected && state.pageId && ! target.explicit ) return false;

        var normalized = normalizePreviewUrl( target.url );
        if ( ! normalized ) return false;

        var changed = normalized !== normalizePreviewUrl( state.pageUrl );
        setActiveTarget( normalized, target.title );
        return changed;
    }

    /* =========================================================================
       VIEW TRANSITIONS
       ========================================================================= */

    function showView( id ) {
        document.querySelectorAll( '.pv-view' ).forEach( function(v) {
            v.classList.remove( 'pv-view--active' );
        } );
        var target = document.getElementById( id );
        if ( target ) {
            // Small rAF ensures CSS transition fires
            requestAnimationFrame( function() {
                requestAnimationFrame( function() {
                    target.classList.add( 'pv-view--active' );
                } );
            } );
        }
    }

    /* =========================================================================
       CUSTOM DROPDOWNS  (.pv-select)
       ========================================================================= */

    function initSelects() {
        document.querySelectorAll( '#pv-root .pv-select' ).forEach( wireSelect );
        document.addEventListener( 'click', function(e) {
            if ( ! e.target.closest( '.pv-select' ) ) closeAllSelects();
        } );
        document.addEventListener( 'keydown', function(e) {
            if ( e.key === 'Escape' ) closeAllSelects();
        } );
    }

    function wireSelect( wrap ) {
        var trigger = wrap.querySelector( '.pv-select__trigger' );
        var menu    = wrap.querySelector( '.pv-select__menu' );
        if ( ! trigger || ! menu ) return;

        trigger.addEventListener( 'click', function(e) {
            e.stopPropagation();
            var open = wrap.classList.contains( 'pv-select--open' );
            closeAllSelects();
            if ( ! open ) {
                wrap.classList.add( 'pv-select--open' );
                trigger.setAttribute( 'aria-expanded', 'true' );
            }
        } );

        // Static options (model selector)
        menu.addEventListener( 'click', function(e) {
            var opt = e.target.closest( '.pv-select__option' );
            if ( ! opt || opt.classList.contains( 'pv-select__option--disabled' ) ) return;
            pickOption( wrap, opt.dataset.value, opt.querySelector( 'span' ).textContent.trim(), null );
            closeAllSelects();
        } );
    }

    function closeAllSelects() {
        document.querySelectorAll( '.pv-select--open' ).forEach( function(w) {
            w.classList.remove( 'pv-select--open' );
            var t = w.querySelector( '.pv-select__trigger' );
            if ( t ) t.setAttribute( 'aria-expanded', 'false' );
        } );
    }

    /** Set a dropdown's selected value, label, and optional extra data attrs. */
    function pickOption( wrap, value, label, extras ) {
        var labelEl = wrap.querySelector( '.pv-select__label' );
        var valEl   = wrap.querySelector( 'input[type="hidden"]' );
        if ( labelEl ) labelEl.textContent = label;
        if ( valEl   ) valEl.value         = value;

        // Extra hidden inputs (page url, title)
        if ( extras ) {
            Object.keys( extras ).forEach( function(id) {
                var el = document.getElementById( id );
                if ( el ) el.value = extras[id];
            } );
        }

        wrap.querySelectorAll( '.pv-select__option' ).forEach( function(o) {
            var active = o.dataset.value === value;
            o.classList.toggle( 'pv-select__option--active', active );
            o.setAttribute( 'aria-selected', active ? 'true' : 'false' );
            var chk = o.querySelector( '.pv-select__check' );
            if ( chk ) chk.hidden = ! active;
        } );

        wrap.dispatchEvent( new CustomEvent( 'pv:change', { detail: { value, label }, bubbles: true } ) );
    }

    /* =========================================================================
       PAGES LOADER
       ========================================================================= */

    function loadPages() {
        var wrap = document.getElementById( 'pv-page-select' );
        var menu = wrap ? wrap.querySelector( '.pv-select__menu' ) : null;
        if ( ! wrap || ! menu || ! window.pvData ) return;

        var fd = new FormData();
        fd.append( 'action', 'pv_get_pages' );
        fd.append( 'nonce',  pvData.nonce );

        fetch( pvData.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
            .then( function(r) { return r.json(); } )
            .then( function(data) {
                menu.innerHTML = '';

                if ( ! data.success || ! data.data.length ) {
                    menu.innerHTML = '<li class="pv-select__loading">No published pages found</li>';
                    return;
                }

                // "None" option
                appendPageOption( wrap, menu, '', '— None (homepage) —', pvData.homeUrl || '' );

                data.data.forEach( function(page) {
                    appendPageOption( wrap, menu, String( page.id ), page.title, page.url || '' );
                } );
            } )
            .catch( function() {
                menu.innerHTML = '<li class="pv-select__loading">Could not load pages</li>';
            } );
    }

    function appendPageOption( wrap, menu, value, label, url ) {
        var li = document.createElement( 'li' );
        li.className      = 'pv-select__option';
        li.dataset.value  = value;
        li.dataset.url    = url;
        li.setAttribute( 'role', 'option' );
        li.setAttribute( 'aria-selected', 'false' );
        li.innerHTML      = '<span>' + esc( label ) + '</span>';

        li.addEventListener( 'click', function() {
            pickOption( wrap, value, label, {
                'pv-page-url':   url,
                'pv-page-title': label,
            } );
            closeAllSelects();
        } );

        menu.appendChild( li );
    }

    /* =========================================================================
       TEMPLATE CHIPS
       ========================================================================= */

    var PROMPTS = {
        'saas-landing':       'Build a complete SaaS landing page with a bold hero section, feature highlights, pricing table, testimonials, and a prominent call-to-action button.',
        'product-landing':    'Build a product landing page with a showcase section, image gallery, feature breakdown, customer reviews, and a buy button.',
        'agency-landing':     'Build an agency landing page with services overview, portfolio grid, team section, client logos, and a contact form.',
        'personal-portfolio': 'Build a personal portfolio page with an about section, skills list, project showcase cards, and a contact form.',
        'startup-waitlist':   'Build a startup waitlist page with a compelling headline, product pitch, email sign-up form, and social sharing buttons.',
    };

    function initChips() {
        var grid   = document.getElementById( 'pv-templates-grid' );
        var prompt = document.getElementById( 'pv-prompt' );
        if ( ! grid ) return;

        grid.addEventListener( 'click', function(e) {
            var chip = e.target.closest( '.pv-chip' );
            if ( ! chip ) return;

            var wasActive = chip.classList.contains( 'pv-chip--active' );
            grid.querySelectorAll( '.pv-chip' ).forEach( function(c) { c.classList.remove( 'pv-chip--active' ); } );

            if ( wasActive ) {
                if ( prompt ) { prompt.value = ''; growTextarea( prompt ); }
                return;
            }

            chip.classList.add( 'pv-chip--active' );
            if ( prompt && PROMPTS[ chip.dataset.template ] ) {
                prompt.value = PROMPTS[ chip.dataset.template ];
                growTextarea( prompt );
                prompt.focus();
            }
        } );
    }

    /* =========================================================================
       TEXTAREA AUTO-GROW
       ========================================================================= */

    function growTextarea( ta ) {
        ta.style.height = 'auto';
        ta.style.height = Math.max( 40, ta.scrollHeight ) + 'px';
    }

    function initAutoGrow() {
        [ 'pv-prompt', 'pv-chat-input' ].forEach( function(id) {
            var ta = document.getElementById( id );
            if ( ta ) ta.addEventListener( 'input', function() { growTextarea( this ); } );
        } );
    }

    /* =========================================================================
       LANDING SUBMIT  →  transition to chat
       ========================================================================= */

    function initLandingSubmit() {
        var btn    = document.getElementById( 'pv-submit' );
        var prompt = document.getElementById( 'pv-prompt' );
        if ( ! btn ) return;

        function doSubmit() {
            var text  = prompt ? prompt.value.trim() : '';
            if ( ! text ) { toast( 'Describe what you want to build first.' ); if ( prompt ) prompt.focus(); return; }

            // Capture page selection
            state.pageId    = ( document.getElementById( 'pv-page-value' )  || {} ).value || '';
            state.pageUrl   = ( document.getElementById( 'pv-page-url' )    || {} ).value || pvData.homeUrl || '';
            state.pageTitle = ( document.getElementById( 'pv-page-title' )  || {} ).value || '';
            state.targetKind = state.pageId ? 'page' : 'live_url';
            state.returnUrl = state.pageUrl;
            state.model     = ( document.getElementById( 'pv-model-value' ) || {} ).value || 'gpt5_4';

            if ( ! state.pageUrl ) state.pageUrl = pvData.homeUrl || '';
            if ( ! state.pageTitle ) state.pageTitle = state.pageUrl ? deriveTitleFromUrl( state.pageUrl ) : 'Homepage';
            syncTargetFromText( text, { overrideSelected: false } );

            // Transition to chat view
            state.view = 'chat';
            showView( 'pv-view-chat' );

            updateChatContext();

            // Load preview
            loadPreview( state.pageUrl || pvData.homeUrl || '', { loadingLabel: 'Loading preview…' } );

            // Clear history for new session
            clearChatSession();

            // Fire the first message
            sendChatMessage( text );
        }

        btn.addEventListener( 'click', doSubmit );

        if ( prompt ) {
            prompt.addEventListener( 'keydown', function(e) {
                if ( ( e.ctrlKey || e.metaKey ) && e.key === 'Enter' ) doSubmit();
            } );
        }
    }

    /* =========================================================================
       CHAT INPUT
       ========================================================================= */

    function initChatInput() {
        var btn   = document.getElementById( 'pv-chat-send' );
        var input = document.getElementById( 'pv-chat-input' );
        if ( ! btn ) return;

        btn.addEventListener( 'click', function() {
            var text = input ? input.value.trim() : '';
            if ( ! text ) return;
            if ( state.busy ) { toast( 'Please wait for the agent to finish.' ); return; }
            if ( input ) { input.value = ''; growTextarea( input ); }
            sendChatMessage( text );
        } );

        if ( input ) {
            input.addEventListener( 'keydown', function(e) {
                if ( ( e.ctrlKey || e.metaKey ) && e.key === 'Enter' ) {
                    btn.click();
                }
            } );
        }
    }

    /* =========================================================================
       NEW CHAT button
       ========================================================================= */

    function initNewChat() {
        var btn = document.getElementById( 'pv-new-chat' );
        if ( ! btn ) return;
        btn.addEventListener( 'click', function() {
            if ( state.streamCtrl ) { state.streamCtrl.abort(); state.streamCtrl = null; }
            setBusy( false );

            if ( state.editorMode ) {
                clearChatSession();
                showView( 'pv-view-chat' );
                loadPreview( state.pageUrl || state.returnUrl || pvData.homeUrl || '', { forceReload: true, loadingLabel: 'Reloading page…' } );
                return;
            }

            state.history   = [];
            state.view      = 'landing';
            state.pageId    = '';
            state.pageUrl   = '';
            state.pageTitle = '';
            state.targetKind = '';
            state.returnUrl = '';
            showView( 'pv-view-landing' );
            resetPreview();
            // Reset landing form
            var prompt = document.getElementById( 'pv-prompt' );
            if ( prompt ) { prompt.value = ''; growTextarea( prompt ); }
            document.querySelectorAll( '.pv-chip--active' ).forEach( function(c) { c.classList.remove( 'pv-chip--active' ); } );
        } );
    }

    /* =========================================================================
       SEND A CHAT MESSAGE  (add to UI + stream from agent)
       ========================================================================= */

    function sendChatMessage( text ) {
        if ( state.busy ) return;

        if ( syncTargetFromText( text, { overrideSelected: false } ) || ! state.previewUrl ) {
            loadPreview( state.pageUrl || pvData.homeUrl || '', { loadingLabel: 'Loading discussed page…' } );
        }

        // Render user bubble
        appendUserMessage( text );

        // Show "thinking" placeholder
        var thinkId = appendThinkingMessage();

        setBusy( true );

        // Kick off SSE stream
        streamAgent( text, thinkId );
    }

    /* =========================================================================
       SSE AGENT STREAM
       ========================================================================= */

    function streamAgent( prompt, thinkId ) {
        var ctrl = new AbortController();
        state.streamCtrl = ctrl;

        var body = new URLSearchParams( {
            action:  'pv_agent_run',
            nonce:   pvData.nonce,
            prompt:  prompt,
            page_id: state.pageId || '',
            model:   state.model || 'gpt5_4',
            history: JSON.stringify( state.history ),
            target_url: state.pageUrl || '',
            target_title: state.pageTitle || '',
            target_kind: state.targetKind || '',
        } );

        var toolGroupEl  = null;  // current tool accordion DOM element
        var toolListEl   = null;
        var toolCount    = 0;
        var finalContent = '';
        var finalUsage   = {};
        var allToolCalls = [];
        var hadError     = false;

        fetch( pvData.ajaxUrl, {
            method:  'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
            signal:  ctrl.signal,
        } )
        .then( function(response) {
            if ( ! response.ok ) throw new Error( 'HTTP ' + response.status );

            var reader  = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer  = '';

            function pump() {
                return reader.read().then( function(chunk) {
                    if ( chunk.done ) return;

                    buffer += decoder.decode( chunk.value, { stream: true } );
                    var lines = buffer.split( '\n' );
                    buffer = lines.pop(); // keep incomplete last line

                    lines.forEach( function(line) {
                        if ( ! line.startsWith( 'data: ' ) ) return;
                        try {
                            var evt = JSON.parse( line.slice( 6 ) );
                            handleAgentEvent( evt );
                        } catch (e) { /* malformed JSON – skip */ }
                    } );

                    return pump();
                } );
            }

            return pump();
        } )
        .catch( function(err) {
            if ( err.name === 'AbortError' ) return;
            removeMessage( thinkId );
            appendErrorMessage( 'Connection error: ' + err.message );
            clearPreviewWatchers();
            setBusy( false );
            hidePreviewLoading();
        } );

        /* ── Handle individual SSE events ── */
        function handleAgentEvent( evt ) {
            var type = evt.type;
            var data = evt.data || {};

            switch ( type ) {

                case 'start':
                    updateThinkingMessage( thinkId, data.message || 'Agent starting…' );
                    break;

                case 'thinking':
                    updateThinkingMessage( thinkId, 'Reasoning… (step ' + ( data.iteration || 1 ) + ')' );
                    break;

                case 'tool_start':
                    // Create tool accordion if first tool
                    if ( ! toolGroupEl ) {
                        var grp = createToolGroup();
                        toolGroupEl = grp.group;
                        toolListEl  = grp.list;
                        // Insert before thinking bubble
                        var thinkEl = document.getElementById( thinkId );
                        var msgs    = document.getElementById( 'pv-chat-messages' );
                        if ( thinkEl && msgs ) msgs.insertBefore( toolGroupEl, thinkEl );
                    }
                    toolCount++;
                    addToolItem( toolListEl, data.id || ( 'tc_' + toolCount ), data.name, null, 'running' );
                    updateToolGroupHeader( toolGroupEl, toolCount, true );
                    scrollBottom( document.getElementById( 'pv-chat-messages' ) );
                    break;

                case 'tool_done':
                    allToolCalls.push( data );
                    updateToolItem( toolListEl, data.id, data.summary, data.success ? 'done' : 'error' );
                    updateToolGroupHeader( toolGroupEl, toolCount, false );
                    scrollBottom( document.getElementById( 'pv-chat-messages' ) );
                    break;

                case 'message':
                    finalContent = data.content  || '';
                    finalUsage   = data.usage    || {};
                    allToolCalls = data.tool_calls || allToolCalls;
                    break;

                case 'error':
                    hadError = true;
                    removeMessage( thinkId );
                    appendErrorMessage( data.message || 'Unknown error from agent.' );
                    clearPreviewWatchers();
                    setBusy( false );
                    hidePreviewLoading();
                    state.streamCtrl = null;
                    break;

                case 'done':
                    if ( hadError ) {
                        state.streamCtrl = null;
                        scrollBottom( document.getElementById( 'pv-chat-messages' ) );
                        break;
                    }

                    // Replace thinking bubble with final assistant message
                    removeMessage( thinkId );
                    if ( finalContent ) {
                        appendAssistantMessage( finalContent, finalUsage );
                        state.history.push( { role: 'assistant', content: finalContent } );
                    } else if ( allToolCalls.length > 0 ) {
                        // Agent ran tool calls but produced no text summary — synthesize one
                        var writes = allToolCalls.filter( function(tc) {
                            return (
                                tc.name === 'write_file' ||
                                tc.name === 'replace_text_in_file' ||
                                tc.name === 'patch_file' ||
                                tc.name === 'make_directory' ||
                                tc.name === 'move_path' ||
                                tc.name === 'delete_path'
                            ) && tc.success;
                        } );
                        var fallback = writes.length > 0
                            ? 'Done — ' + writes.length + ' change' + ( writes.length > 1 ? 's' : '' ) + ' applied: ' +
                              writes.map( function(tc) {
                                  if ( tc.args.path ) return '`' + tc.args.path + '`';
                                  if ( tc.args.to_path ) return '`' + tc.args.to_path + '`';
                                  if ( tc.args.from_path ) return '`' + tc.args.from_path + '`';
                                  return '`change`';
                              } ).join( ', ' ) +
                              '. Refresh the preview to see changes.'
                            : 'I searched through your files but could not find the exact content to change. Please be more specific or try selecting a page context first.';
                        appendAssistantMessage( fallback, finalUsage );
                        state.history.push( { role: 'assistant', content: fallback } );
                    }

                    syncTargetFromText( finalContent, { allowImplicit: false, overrideSelected: true } );
                    setBusy( false );
                    refreshPreview( 'Loading updated page…' );
                    state.streamCtrl = null;
                    scrollBottom( document.getElementById( 'pv-chat-messages' ) );
                    break;
            }
        }

        // Also store user message in history immediately
        state.history.push( { role: 'user', content: prompt } );
    }

    /* =========================================================================
       MESSAGE BUILDERS
       ========================================================================= */

    function msgId() { return 'msg-' + Date.now() + '-' + Math.random().toString(36).slice(2,7); }

    /** User bubble */
    function appendUserMessage( text ) {
        var id  = msgId();
        var el  = document.createElement( 'div' );
        el.id        = id;
        el.className = 'pv-msg pv-msg--user';
        el.innerHTML =
            '<div class="pv-msg__bubble">' + esc( text ).replace( /\n/g, '<br>' ) + '</div>';
        getMessages().appendChild( el );
        scrollBottom( getMessages() );
        return id;
    }

    /** Animated "thinking" placeholder */
    function appendThinkingMessage( label ) {
        var id  = msgId();
        var el  = document.createElement( 'div' );
        el.id        = id;
        el.className = 'pv-msg pv-msg--assistant pv-msg--thinking';
        el.innerHTML =
            '<div class="pv-msg__header">' + agentAvatar() + '<span class="pv-msg__name">Agent</span></div>' +
            '<div class="pv-msg__bubble">' +
                '<span class="pv-dot"></span><span class="pv-dot"></span><span class="pv-dot"></span>' +
                '<span style="margin-left:6px;font-size:13px;color:var(--text-50)">' + esc( label || 'Starting…' ) + '</span>' +
            '</div>';
        getMessages().appendChild( el );
        scrollBottom( getMessages() );
        return id;
    }

    function updateThinkingMessage( id, label ) {
        var el = document.getElementById( id );
        if ( ! el ) return;
        var span = el.querySelector( 'span[style]' );
        if ( span ) span.textContent = label;
    }

    /** Final assistant response */
    function appendAssistantMessage( content, usage ) {
        var id  = msgId();
        var el  = document.createElement( 'div' );
        el.id        = id;
        el.className = 'pv-msg pv-msg--assistant';

        var tokens = usage && usage.total_tokens ? usage.total_tokens + ' tokens' : '';

        el.innerHTML =
            '<div class="pv-msg__header">' +
                agentAvatar() +
                '<span class="pv-msg__name">PressViber</span>' +
                ( tokens ? '<span class="pv-msg__tokens">' + esc( tokens ) + '</span>' : '' ) +
            '</div>' +
            '<div class="pv-msg__bubble">' + renderMarkdown( content ) + '</div>' +
            '<div class="pv-msg__actions">' +
                '<button class="pv-msg__copy" type="button" data-msgid="' + id + '">' +
                    copyIcon() + ' Copy' +
                '</button>' +
            '</div>';

        getMessages().appendChild( el );

        // Wire copy button
        el.querySelector( '.pv-msg__copy' ).addEventListener( 'click', function() {
            navigator.clipboard.writeText( content ).then( function() { toast( 'Copied to clipboard.' ); } );
        } );

        return id;
    }

    function appendErrorMessage( msg ) {
        var el = document.createElement( 'div' );
        el.className = 'pv-msg pv-msg--assistant';
        el.innerHTML =
            '<div class="pv-msg__bubble" style="border-color:rgba(255,100,100,.35);color:rgba(255,180,180,.9)">' +
            '⚠ ' + esc( msg ) + '</div>';
        getMessages().appendChild( el );
        scrollBottom( getMessages() );
    }

    function removeMessage( id ) {
        var el = document.getElementById( id );
        if ( el ) el.remove();
    }

    /* =========================================================================
       TOOL CALL GROUP (accordion)
       ========================================================================= */

    function createToolGroup() {
        var group = document.createElement( 'div' );
        group.className = 'pv-tool-group pv-tool-group--open';

        var header = document.createElement( 'button' );
        header.type      = 'button';
        header.className = 'pv-tool-group__header';
        header.setAttribute( 'aria-expanded', 'true' );
        header.innerHTML =
            toolSpinner() +
            '<span class="pv-tool-group__label">Working…</span>' +
            '<svg class="pv-tool-group__toggle" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

        header.addEventListener( 'click', function() {
            group.classList.toggle( 'pv-tool-group--open' );
            header.setAttribute( 'aria-expanded', group.classList.contains( 'pv-tool-group--open' ) ? 'true' : 'false' );
        } );

        var list = document.createElement( 'div' );
        list.className = 'pv-tool-group__list';

        group.appendChild( header );
        group.appendChild( list );

        getMessages().appendChild( group );
        return { group: group, list: list };
    }

    function updateToolGroupHeader( group, count, running ) {
        if ( ! group ) return;
        var label = group.querySelector( '.pv-tool-group__label' );
        if ( label ) label.textContent = running
            ? count + ' operation' + ( count === 1 ? '' : 's' ) + '…'
            : count + ' operation' + ( count === 1 ? '' : 's' ) + ' completed';

        var icon = group.querySelector( '.pv-tool-group__header > svg:first-child' );
        if ( icon && ! running ) {
            icon.outerHTML = checkIcon();
        }
    }

    function addToolItem( list, id, name, summary, state ) {
        if ( ! list ) return;
        var item = document.createElement( 'div' );
        item.id        = 'tool-' + id;
        item.className = 'pv-tool-item pv-tool-item--' + state;
        item.innerHTML =
            '<span class="pv-tool-item__icon">' + toolItemIcon( state ) + '</span>' +
            '<span class="pv-tool-item__summary">' + esc( name ) + ( summary ? ': ' + esc( summary ) : '' ) + '</span>';
        list.appendChild( item );
    }

    function updateToolItem( list, id, summary, newState ) {
        var item = document.getElementById( 'tool-' + id );
        if ( ! item ) return;
        item.className = 'pv-tool-item pv-tool-item--' + newState;
        item.querySelector( '.pv-tool-item__icon' ).innerHTML = toolItemIcon( newState );
        var sumEl = item.querySelector( '.pv-tool-item__summary' );
        if ( sumEl && summary ) sumEl.textContent = summary;
    }

    /* =========================================================================
       SVG ICON HELPERS
       ========================================================================= */

    function agentAvatar() {
        return '<span class="pv-msg__avatar">' +
            '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2c.3 3.8 2.2 5.7 6 6-3.8.3-5.7 2.2-6 6-.3-3.8-2.2-5.7-6-6 3.8-.3 5.7-2.2 6-6z"/></svg>' +
            '</span>';
    }

    function toolSpinner() {
        return '<svg class="pv-send__spinner" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0110 10"/></svg>';
    }

    function checkIcon() {
        return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(150,255,150,.8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    }

    function copyIcon() {
        return '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
    }

    function toolItemIcon( state ) {
        if ( state === 'running' ) return toolSpinner();
        if ( state === 'done'    ) return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(150,255,150,.8)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        if ( state === 'error'   ) return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgba(255,130,130,.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        return '';
    }

    function getMessages() { return document.getElementById( 'pv-chat-messages' ); }

    /* =========================================================================
       BUSY STATE
       ========================================================================= */

    function setBusy( on ) {
        state.busy = on;

        // Chat send button
        var chatSend = document.getElementById( 'pv-chat-send' );
        if ( chatSend ) {
            chatSend.disabled = on;
            setButtonLoading( chatSend, on );
        }

        // Chat input
        var chatInput = document.getElementById( 'pv-chat-input' );
        if ( chatInput ) chatInput.disabled = false;

        if ( on ) {
            showPreviewLoading( 'Applying changes…' );
        } else {
            clearPreviewWatchers();
            hidePreviewLoading();
        }
    }

    function setButtonLoading( button, on ) {
        if ( ! button ) return;

        var icon    = button.querySelector( '.pv-send__icon' );
        var spinner = button.querySelector( '.pv-send__spinner' );

        if ( icon ) {
            icon.hidden = on;
            icon.style.display = on ? 'none' : '';
        }

        if ( spinner ) {
            spinner.hidden = ! on;
            spinner.style.display = on ? 'block' : 'none';
        }

        button.classList.toggle( 'pv-chat-send--loading', on );
    }

    /* =========================================================================
       PREVIEW PANEL
       ========================================================================= */

    function updatePreviewMeta( url ) {
        var normalized = normalizePreviewUrl( url );
        var urlText    = document.getElementById( 'pv-preview-url-text' );
        var openLink   = document.getElementById( 'pv-preview-open' );

        if ( urlText ) {
            urlText.textContent = normalized || '';
            urlText.title = normalized || '';
        }

        if ( openLink ) {
            openLink.href = normalized || '#';
            if ( normalized ) {
                openLink.removeAttribute( 'aria-disabled' );
            } else {
                openLink.setAttribute( 'aria-disabled', 'true' );
            }
        }
    }

    function showPreviewLoading( label ) {
        var loading = document.getElementById( 'pv-preview-loading' );
        var text    = document.getElementById( 'pv-preview-loading-label' );
        if ( text ) text.textContent = label || 'Loading preview…';
        if ( loading ) {
            loading.hidden = false;
            loading.style.display = 'flex';
        }
    }

    function hidePreviewLoading() {
        var loading = document.getElementById( 'pv-preview-loading' );
        if ( loading ) {
            loading.hidden = true;
            loading.style.display = 'none';
        }
    }

    function clearPreviewWatchers() {
        if ( state.previewPoll ) {
            clearInterval( state.previewPoll );
            state.previewPoll = null;
        }

        if ( state.previewTimeout ) {
            clearTimeout( state.previewTimeout );
            state.previewTimeout = null;
        }
    }

    function finalizePreviewLoad( iframe, options ) {
        options = options || {};

        if ( options.requestId && options.requestId !== state.previewRequestId ) {
            return;
        }

        clearPreviewWatchers();

        if ( ! state.busy || options.allowHideWhileBusy ) {
            hidePreviewLoading();
        }

        if ( iframe ) {
            iframe.dataset.pvLoading = '0';
        }
    }

    function watchPreviewReady( iframe, options ) {
        clearPreviewWatchers();

        state.previewTimeout = setTimeout( function() {
            finalizePreviewLoad( iframe, options );
        }, options.timeout || 15000 );

        state.previewPoll = setInterval( function() {
            if ( ! iframe ) return;

            try {
                var doc = iframe.contentDocument || ( iframe.contentWindow ? iframe.contentWindow.document : null );
                if ( doc && doc.readyState === 'complete' ) {
                    finalizePreviewLoad( iframe, options );
                }
            } catch ( e ) {
                // Ignore cross-origin access errors and rely on the iframe load event / timeout fallback.
            }
        }, 250 );
    }

    function cacheBustUrl( url ) {
        var normalized = normalizePreviewUrl( url );
        if ( ! normalized ) return '';

        try {
            var parsed = new URL( normalized );
            parsed.searchParams.set( 'pv_r', Date.now() );
            return parsed.toString();
        } catch ( e ) {
            return normalized;
        }
    }

    function buildPreviewFrameUrl( url ) {
        var normalized = normalizePreviewUrl( url );
        if ( ! normalized ) return '';

        try {
            var parsed = new URL( normalized );
            parsed.searchParams.set( 'pv_preview', '1' );
            return parsed.toString();
        } catch ( e ) {
            return normalized;
        }
    }

    function loadPreview( url, options ) {
        options = options || {};
        url = normalizePreviewUrl( url || state.pageUrl || state.previewUrl || homeUrl() );
        if ( ! url ) {
            hidePreviewLoading();
            return;
        }

        state.previewUrl = url;
        updatePreviewMeta( url );
        updatePreviewDevice();

        var iframe = document.getElementById( 'pv-preview-iframe' );
        if ( ! iframe ) return;

        var frameUrl = buildPreviewFrameUrl( url );
        var currentSrc = normalizePreviewUrl( iframe.getAttribute( 'src' ) || iframe.src || '' );
        if ( ! options.forceReload && currentSrc && currentSrc === normalizePreviewUrl( frameUrl ) ) {
            hidePreviewLoading();
            return;
        }

        state.previewRequestId += 1;
        options.requestId = state.previewRequestId;

        showPreviewLoading( options.loadingLabel || 'Loading preview…' );
        iframe.dataset.pvLoading = '1';
        iframe.dataset.pvRequestId = String( options.requestId );

        iframe.onload = function() {
            finalizePreviewLoad( iframe, options );
        };

        iframe.onerror = function() {
            finalizePreviewLoad( iframe, options );
        };

        watchPreviewReady( iframe, options );

        var nextSrc  = options.forceReload ? cacheBustUrl( frameUrl ) : frameUrl;
        iframe.src = nextSrc;

        window.setTimeout( function() {
            if ( iframe.dataset.pvRequestId !== String( options.requestId ) ) {
                return;
            }

            try {
                var doc = iframe.contentDocument || ( iframe.contentWindow ? iframe.contentWindow.document : null );
                if ( doc && doc.readyState === 'complete' ) {
                    finalizePreviewLoad( iframe, options );
                    return;
                }
            } catch ( e ) {
                // Ignore same-origin access failures and rely on the fallback timeout below.
            }

            finalizePreviewLoad( iframe, options );
        }, options.forceHideDelay || 12000 );
    }

    function refreshPreview( label ) {
        var target = normalizePreviewUrl( state.pageUrl || state.previewUrl || homeUrl() );
        if ( ! target ) {
            hidePreviewLoading();
            return;
        }
        loadPreview( target, { forceReload: true, loadingLabel: label || 'Loading updated page…', allowHideWhileBusy: true } );
    }

    function resetPreview() {
        state.previewUrl = '';
        clearPreviewWatchers();
        updatePreviewMeta( '' );
        hidePreviewLoading();

        var iframe = document.getElementById( 'pv-preview-iframe' );
        if ( iframe ) iframe.src = 'about:blank';
    }

    function initPreviewControls() {
        var refresh  = document.getElementById( 'pv-preview-refresh' );
        var desktop  = document.getElementById( 'pv-preview-desktop' );
        var mobile   = document.getElementById( 'pv-preview-mobile' );
        if ( refresh ) {
            refresh.addEventListener( 'click', function() {
                refreshPreview( 'Refreshing preview…' );
            } );
        }

        if ( desktop ) {
            desktop.addEventListener( 'click', function() {
                state.previewDevice = 'desktop';
                updatePreviewDevice();
            } );
        }

        if ( mobile ) {
            mobile.addEventListener( 'click', function() {
                state.previewDevice = 'mobile';
                updatePreviewDevice();
            } );
        }
    }

    function initChatResizer() {
        var handle = document.getElementById( 'pv-chat-resizer' );
        var body   = document.querySelector( '.pv-chat-body' );
        var page   = document.getElementById( 'pv-admin-page' );
        if ( ! handle || ! body || ! page ) return;

        function clampWidth( value ) {
            var min = 240;
            var max = Math.max( 420, Math.floor( body.getBoundingClientRect().width * 0.55 ) );
            return Math.min( max, Math.max( min, value ) );
        }

        function applyWidth( value ) {
            page.style.setProperty( '--pv-chat-panel-width', clampWidth( value ) + 'px' );
        }

        handle.addEventListener( 'mousedown', function(e) {
            e.preventDefault();
            body.classList.add( 'is-resizing' );

            function onMove( event ) {
                applyWidth( event.clientX - body.getBoundingClientRect().left );
            }

            function onUp() {
                body.classList.remove( 'is-resizing' );
                window.removeEventListener( 'mousemove', onMove );
                window.removeEventListener( 'mouseup', onUp );
            }

            window.addEventListener( 'mousemove', onMove );
            window.addEventListener( 'mouseup', onUp );
        } );

        handle.addEventListener( 'keydown', function(e) {
            if ( e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' ) return;
            e.preventDefault();
            var panel = document.querySelector( '.pv-chat-panel' );
            var current = panel ? panel.getBoundingClientRect().width : 320;
            applyWidth( current + ( e.key === 'ArrowRight' ? 24 : -24 ) );
        } );
    }

    /* =========================================================================
       LIGHTWEIGHT MARKDOWN RENDERER
       ========================================================================= */

    function renderMarkdown( text ) {
        var lines     = text.split( '\n' );
        var html      = '';
        var inCode    = false;
        var codeLang  = '';
        var codeLines = [];

        lines.forEach( function( line ) {
            var fence = line.match( /^```(\w*)/ );
            if ( fence ) {
                if ( ! inCode ) {
                    inCode   = true;
                    codeLang = fence[1] || '';
                    codeLines = [];
                } else {
                    var label = codeLang ? '<span class="pv-code-label">' + esc( codeLang ) + '</span>' : '';
                    html += label + '<pre><code>' + esc( codeLines.join('\n') ) + '</code></pre>';
                    inCode   = false; codeLang = ''; codeLines = [];
                }
                return;
            }
            if ( inCode ) { codeLines.push( line ); return; }

            var m;
            if ( ( m = line.match( /^### (.+)/ ) ) ) { html += '<h3>' + inline( m[1] ) + '</h3>'; return; }
            if ( ( m = line.match( /^## (.+)/  ) ) ) { html += '<h2>' + inline( m[1] ) + '</h2>'; return; }
            if ( ( m = line.match( /^# (.+)/   ) ) ) { html += '<h1>' + inline( m[1] ) + '</h1>'; return; }
            if ( /^---+$/.test( line.trim() ) )       { html += '<hr>'; return; }
            if ( ( m = line.match( /^[-*+] (.+)/ ) ) ) { html += '<ul><li>' + inline( m[1] ) + '</li></ul>'; return; }
            if ( ( m = line.match( /^\d+\. (.+)/ ) ) ) { html += '<ol><li>' + inline( m[1] ) + '</li></ol>'; return; }
            if ( line.trim() === '' )                   { html += '</p><p>'; return; }
            html += inline( line ) + ' ';
        } );

        if ( inCode && codeLines.length ) {
            html += '<pre><code>' + esc( codeLines.join('\n') ) + '</code></pre>';
        }

        html = '<p>' + html + '</p>';
        html = html.replace( /<p>\s*<\/p>/g, '' );
        html = html.replace( /<\/ul>\s*<ul>/g, '' );
        html = html.replace( /<\/ol>\s*<ol>/g, '' );
        return html;
    }

    function inline( text ) {
        text = esc( text );
        text = text.replace( /`([^`]+)`/g,    '<code>$1</code>' );
        text = text.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
        text = text.replace( /__(.+?)__/g,     '<strong>$1</strong>' );
        text = text.replace( /\*(.+?)\*/g,     '<em>$1</em>' );
        text = text.replace( /_([^_]+)_/g,     '<em>$1</em>' );
        return text;
    }

    /* =========================================================================
       BOOT
       ========================================================================= */

    function boot() {
        initSelects();
        initModelSync();
        loadPages();
        initChips();
        initAutoGrow();
        initLandingSubmit();
        initChatInput();
        initNewChat();
        initPreviewControls();
        initChatResizer();
        bootInitialState();
    }

    function bootInitialState() {
        var initial = getInitialContext();

        state.editorMode = !! ( window.pvData && pvData.editorMode );
        state.returnUrl  = normalizePreviewUrl( initial.returnUrl || initial.url || '' );
        state.targetKind = initial.targetKind || '';

        updateExitLink();
        updateChatPlaceholder();

        if ( ! initial.startInEditor ) {
            return;
        }

        state.view      = 'chat';
        state.pageId    = initial.pageId ? String( initial.pageId ) : '';
        state.pageUrl   = normalizePreviewUrl( initial.url || homeUrl() );
        state.pageTitle = initial.title || deriveTitleFromUrl( state.pageUrl );
        state.model     = ( document.getElementById( 'pv-model-value' ) || {} ).value || 'gpt5_4';

        clearChatSession();
        updateChatContext();
        updatePreviewMeta( state.pageUrl || homeUrl() );
        updatePreviewDevice();
        showView( 'pv-view-chat' );

        var iframe = document.getElementById( 'pv-preview-iframe' );
        if ( ! iframe || ! normalizePreviewUrl( iframe.getAttribute( 'src' ) || iframe.src || '' ) ) {
            loadPreview( state.pageUrl || homeUrl(), { loadingLabel: 'Loading current page…' } );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }

} )();
