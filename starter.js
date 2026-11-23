const status = document.querySelector('#status');
const login = document.querySelector('#login');
const home = document.querySelector('#home');
const logout = document.querySelector('#logout');
const emptyFeed = document.querySelector('#empty-feed');
const openComposer = document.querySelector('#open-composer');
const closeComposer = document.querySelector('#close-composer');
const composer = document.querySelector('#composer');
const postText = document.querySelector('#post-text');
const previewButton = document.querySelector('#preview-button');
const postPreview = document.querySelector('#post-preview');
const previewText = document.querySelector('#preview-text');
let csrf;

async function request(route, payload) {
    const headers = { Accept: 'application/json' };
    const options = { headers };
    if (payload !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = csrf;
        options.method = 'POST';
        options.body = JSON.stringify(payload);
    }
    const response = await fetch('/api.php?r=' + route, options);
    const result = await response.json();
    if (!response.ok) {
        throw new Error(result.error?.message ?? 'Запрос не выполнен.');
    }
    csrf = result.data.csrf ?? csrf;
    return result.data;
}

function show(user) {
    document.querySelector('#greeting').textContent = user ? 'Вы вошли как ' + user.display_name + '.' : '';
    login.hidden = Boolean(user);
    home.hidden = !user;
    if (!user) {
        composer.reset();
        previewButton.disabled = true;
        postPreview.hidden = true;
        previewText.textContent = '';
        showComposer(false);
    }
    status.textContent = '';
}

function showError(error) {
    status.textContent = error.message;
}

login.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = login.querySelector('button');
    submit.disabled = true;
    try {
        const { user } = await request('login', Object.fromEntries(new FormData(login)));
        login.reset();
        show(user);
    } catch (error) {
        showError(error);
    } finally {
        submit.disabled = false;
    }
});

logout.addEventListener('click', async () => {
    logout.disabled = true;
    try {
        await request('logout', {});
        show(null);
        await refresh();
    } catch (error) {
        showError(error);
    } finally {
        logout.disabled = false;
    }
});

function showComposer(open) {
    emptyFeed.hidden = open;
    composer.hidden = !open;
}

openComposer.addEventListener('click', () => {
    showComposer(true);
    postText.focus();
});

closeComposer.addEventListener('click', () => {
    showComposer(false);
    openComposer.focus();
});

postText.addEventListener('input', () => {
    previewButton.disabled = !postText.value.trim();
    postPreview.hidden = true;
    previewText.textContent = '';
});

composer.addEventListener('submit', event => {
    event.preventDefault();
    const text = postText.value.trim();
    if (text) {
        postPreview.hidden = false;
        previewText.textContent = text;
    }
});

async function refresh() {
    const { user } = await request('bootstrap');
    show(user);
}

refresh().catch(showError);
