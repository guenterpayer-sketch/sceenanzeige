/**
 * modules/community/frontend.js
 *
 * Holt den Community-Feed über proxies/nc.php (action=community). Genau
 * wie beim Stundenplan-Modul wird SAAL_ID + modul_instanz_id benötigt.
 */
(function () {
    window.TanzschuleModule = window.TanzschuleModule || {};

    function renderPost(post) {
        const content = post.content || {};
        let inner = '';
        if (content.type === 'image' && content.image) {
            inner += '<img class="tm-community-bild" src="' + content.image + '" alt="">';
        }
        if (content.content) {
            inner += '<p class="tm-community-text">' + content.content + '</p>';
        }
        return '<div class="tm-community-post">' + inner + '</div>';
    }

    window.TanzschuleModule.community = function (container, settings, inhalte, modulInstanzId) {
        settings = settings || {};
        container.classList.add('tm-modul-community');

        const basisUrl = window.BACKEND_BASE || '';
        const saalId = window.SAAL_ID;

        if (!saalId || !modulInstanzId) {
            container.innerHTML = '<div class="tm-community-fehler">SAAL_ID/modul_instanz_id fehlt</div>';
            return;
        }

        function laden() {
            const url = basisUrl + '/proxies/nc.php?action=community' +
                '&saal_id=' + encodeURIComponent(saalId) +
                '&modul_instanz_id=' + encodeURIComponent(modulInstanzId);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        container.innerHTML = '<div class="tm-community-fehler">' + data.error + '</div>';
                        return;
                    }
                    const items = (data.feed && data.feed.items) || [];
                    container.innerHTML = items.length
                        ? items.map(renderPost).join('')
                        : '<div class="tm-community-leer">Keine Beiträge</div>';
                })
                .catch(function () {
                    container.innerHTML = '<div class="tm-community-fehler">Verbindung unterbrochen</div>';
                });
        }

        laden();
        if (container._tmInterval) {
            clearInterval(container._tmInterval);
        }
        container._tmInterval = setInterval(laden, 60000);
    };
})();
