document.addEventListener(
    'click',
    function (event) {
        if (!event.altKey) {
            return;
        }

        var link = event.target.closest('a[href]');
        if (!link) {
            return;
        }

        // Prevent accidental browser download behavior from Alt+Click on links.
        event.preventDefault();
        event.stopPropagation();
    },
    true
);
