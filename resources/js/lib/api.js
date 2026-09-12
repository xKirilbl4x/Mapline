export class ApiError extends Error {
    constructor(message, status = 0, data = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

function readCookie(name) {
    const value = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith(`${name}=`))
        ?.split('=')
        .slice(1)
        .join('=');

    return value ? decodeURIComponent(value) : null;
}

function applicationPath(path) {
    const publicPath = '/public';
    const currentPath = window.location.pathname;
    const basePath = currentPath === publicPath || currentPath.startsWith(`${publicPath}/`)
        ? publicPath
        : '';

    return `${basePath}${path.startsWith('/') ? path : `/${path}`}`;
}

function validationMessage(data) {
    if (!data?.errors) {
        return data?.message || 'Не удалось выполнить запрос.';
    }

    return Object.values(data.errors)
        .flat()
        .filter(Boolean)
        .join(' ');
}

async function parseResponse(response) {
    const text = await response.text();

    if (!text) {
        return null;
    }

    try {
        return JSON.parse(text);
    } catch {
        return { message: text };
    }
}

export async function csrfCookie() {
    const response = await fetch(applicationPath('/sanctum/csrf-cookie'), {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        const data = await parseResponse(response);
        throw new ApiError(validationMessage(data), response.status, data);
    }
}

export async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');

    let body = options.body;
    if (body !== undefined && body !== null && typeof body !== 'string') {
        body = JSON.stringify(body);
        headers.set('Content-Type', 'application/json');
    }

    const xsrfToken = readCookie('XSRF-TOKEN');
    if (xsrfToken) {
        headers.set('X-XSRF-TOKEN', xsrfToken);
    }

    const response = await fetch(applicationPath(path), {
        ...options,
        body,
        credentials: 'same-origin',
        headers,
    });
    const data = await parseResponse(response);

    if (!response.ok) {
        throw new ApiError(
            validationMessage(data),
            response.status,
            data,
        );
    }

    return data;
}
