const status = document.querySelector('#status');
const login = document.querySelector('#login');
const home = document.querySelector('#home');
const logout = document.querySelector('#logout');
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
        await refresh();
    } catch (error) {
        showError(error);
    } finally {
        logout.disabled = false;
    }
});

async function refresh() {
    const { user } = await request('bootstrap');
    show(user);
}

refresh().catch(showError);
