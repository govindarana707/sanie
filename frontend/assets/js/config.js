// Application configuration. This file must load before every application script.
(function configureApplication(window) {
    const protocol = window.location.protocol;
    const host = window.location.host;
    const path = window.location.pathname || '/';
    const frontendPath = path.match(/^(.*?)(?:\/frontend)(?:\/|$)/i);
    const projectPath = frontendPath ? frontendPath[1].replace(/\/$/, '') : '';

    window.APP_CONFIG = Object.freeze({
        API_BASE: host ? `${protocol}//${host}${projectPath}/backend/api` : ''
    });
})(window);
