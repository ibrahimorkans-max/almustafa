/*
 * Passkeys (WebAuthn) in the browser, without a build step. The server sends the standard JSON
 * options; the browser's own credential API creates or uses the key after the fingerprint, face or
 * device PIN; the answer goes back as JSON. Binary fields travel as base64url text.
 */
(function () {
    const b64urlToBytes = (s) => {
        const pad = '='.repeat((4 - (s.length % 4)) % 4);
        const bin = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(bin, (c) => c.charCodeAt(0)).buffer;
    };
    const bytesToB64url = (buf) => {
        const bytes = new Uint8Array(buf);
        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };
    const parse = (o) => (typeof o === 'string' ? JSON.parse(o) : o);

    const creationOptions = (json) => {
        const o = parse(json);
        if (window.PublicKeyCredential && PublicKeyCredential.parseCreationOptionsFromJSON) {
            return PublicKeyCredential.parseCreationOptionsFromJSON(o);
        }
        o.challenge = b64urlToBytes(o.challenge);
        o.user.id = b64urlToBytes(o.user.id);
        (o.excludeCredentials || []).forEach((c) => (c.id = b64urlToBytes(c.id)));
        return o;
    };
    const requestOptions = (json) => {
        const o = parse(json);
        if (window.PublicKeyCredential && PublicKeyCredential.parseRequestOptionsFromJSON) {
            return PublicKeyCredential.parseRequestOptionsFromJSON(o);
        }
        o.challenge = b64urlToBytes(o.challenge);
        (o.allowCredentials || []).forEach((c) => (c.id = b64urlToBytes(c.id)));
        return o;
    };
    const toJSON = (cred) => {
        if (typeof cred.toJSON === 'function') return JSON.stringify(cred.toJSON());
        const r = cred.response;
        const response = r.attestationObject
            ? { clientDataJSON: bytesToB64url(r.clientDataJSON), attestationObject: bytesToB64url(r.attestationObject), transports: r.getTransports ? r.getTransports() : [] }
            : { clientDataJSON: bytesToB64url(r.clientDataJSON), authenticatorData: bytesToB64url(r.authenticatorData), signature: bytesToB64url(r.signature), userHandle: r.userHandle ? bytesToB64url(r.userHandle) : null };
        return JSON.stringify({ id: cred.id, rawId: bytesToB64url(cred.rawId), type: cred.type, response, clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {}, authenticatorAttachment: cred.authenticatorAttachment || null });
    };
    const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
    const explain = (e) => (e && e.name === 'NotAllowedError' ? 'Cancelled, or no passkey for this site on this device.' : 'Passkeys do not work here: ' + (e && e.message ? e.message : e));

    /** Sign-in page: ask the server for a challenge, let the device sign it, send it back. */
    window.rmPasskeyLogin = async function ($wire) {
        if (!supported()) { alertBox('This browser cannot use passkeys here. Use the password instead.'); return; }
        try {
            const options = await $wire.passkeyOptions();
            const cred = await navigator.credentials.get({ publicKey: requestOptions(options) });
            await $wire.passkeyLogin(toJSON(cred));
        } catch (e) { alertBox(explain(e)); }
    };

    /** "My passkeys" page: create a key on this device and store its public half. */
    window.rmPasskeyRegister = async function ($wire, name) {
        if (!supported()) { alertBox('This browser cannot create passkeys here.'); return; }
        try {
            const options = await $wire.registrationOptions();
            const cred = await navigator.credentials.create({ publicKey: creationOptions(options) });
            await $wire.storePasskey(toJSON(cred), name);
        } catch (e) { alertBox(explain(e)); }
    };

    // A plain message without blocking dialogs (window.alert would freeze automated browsers too).
    function alertBox(text) {
        if (window.FilamentNotification) { new window.FilamentNotification().title(text).warning().send(); return; }
        console.warn(text);
    }
})();
