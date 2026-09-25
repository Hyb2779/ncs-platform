import Alpine from 'alpinejs';
import translations from 'virtual:i18n';

window.Alpine = Alpine;
window.translations = translations;

window.makeUuid = function makeUuid() {
    try {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
    } catch (error) {
        // HTTP pages reject randomUUID(); fall through to the RFC4122 v4 fallback.
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);

        return v.toString(16);
    });
};

Alpine.start();
