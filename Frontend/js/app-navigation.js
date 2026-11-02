(function () {
    if (window.MelodyMatchNavigation) return;

    const appHtmlRoot = new URL('/MelodyMatch/Frontend/html/', window.location.href);

    function shouldUseFullNavigation(url) {
        return url.pathname === `${appHtmlRoot.pathname}login.html` ||
            url.pathname === `${appHtmlRoot.pathname}register.html`;
    }

    function updatePageStyles(targetDocument) {
        document.querySelectorAll('[data-app-page-stylesheet]:not(#music-player-stylesheet)')
            .forEach(link => link.remove());
        targetDocument.querySelectorAll('link[rel="stylesheet"]').forEach(sourceLink => {
            const link = document.createElement('link');
            Array.from(sourceLink.attributes).forEach(attribute => {
                link.setAttribute(attribute.name, attribute.value);
            });
            link.dataset.appPageStylesheet = 'true';
            document.head.append(link);
        });
    }

    function loadExternalScript(source) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = source;
            script.onload = resolve;
            script.onerror = () => reject(new Error(`Could not load page script: ${source}`));
            document.body.append(script);
        });
    }

    async function runPageScripts(scripts) {
        for (const script of scripts) {
            if (script.src) {
                const sourceUrl = new URL(script.src, window.location.href);
                if (sourceUrl.pathname.endsWith('/music-player.js') ||
                    sourceUrl.pathname.endsWith('/app-navigation.js')) {
                    continue;
                }
                await loadExternalScript(sourceUrl.href);
            } else if (script.textContent.trim()) {
                new Function(script.textContent).call(window);
            }
        }
    }

    async function navigate(url, addHistory) {
        if (url.origin !== window.location.origin || !url.pathname.startsWith(appHtmlRoot.pathname)) {
            return false;
        }
        if (shouldUseFullNavigation(url)) {
            window.MelodyMatchMusicPlayer.stop('');
            window.location.assign(url.href);
            return true;
        }

        try {
            const response = await fetch(url.href, {
                headers: { Accept: 'text/html' },
                cache: 'no-store'
            });
            if (response.redirected) {
                window.MelodyMatchMusicPlayer.stop('');
                window.location.assign(response.url);
                return true;
            }
            if (!response.ok || !(response.headers.get('content-type') || '').includes('text/html')) {
                throw new Error('The requested page could not be loaded.');
            }

            const html = await response.text();
            const targetDocument = new DOMParser().parseFromString(html, 'text/html');
            const baseElement = targetDocument.createElement('base');
            baseElement.href = url.href;
            targetDocument.head.prepend(baseElement);
            const scripts = Array.from(targetDocument.body.querySelectorAll('script'));
            targetDocument.body.querySelectorAll('script').forEach(script => script.remove());
            const playerDock = document.getElementById('musicPlayerDock');

            document.body.className = targetDocument.body.className;
            if (!playerDock.hidden) {
                document.body.classList.add('music-player-active');
            }
            Array.from(document.body.children).forEach(child => {
                if (child !== playerDock) child.remove();
            });
            Array.from(targetDocument.body.children).forEach(child => {
                document.body.insertBefore(document.adoptNode(child), playerDock);
            });
            document.title = targetDocument.title;
            updatePageStyles(targetDocument);
            if (addHistory) {
                window.history.pushState({}, '', url.href);
            }
            window.scrollTo(0, 0);
            await runPageScripts(scripts);
            await window.MelodyMatchMusicPlayer.syncAccount();
            return true;
        } catch (error) {
            console.error('In-app navigation failed.', error);
            window.location.assign(url.href);
            return true;
        }
    }

    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download') ||
            (link.target && link.target !== '_self')) {
            return;
        }
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || !url.pathname.startsWith(appHtmlRoot.pathname)) {
            return;
        }
        if (url.pathname === window.location.pathname &&
            url.search === window.location.search &&
            url.hash) {
            return;
        }
        event.preventDefault();
        navigate(url, true);
    });

    window.addEventListener('popstate', () => {
        navigate(new URL(window.location.href), false);
    });

    document.querySelectorAll('link[rel="stylesheet"]:not(#music-player-stylesheet)').forEach(link => {
        link.dataset.appPageStylesheet = 'true';
    });
    window.MelodyMatchNavigation = { navigate };
}());
