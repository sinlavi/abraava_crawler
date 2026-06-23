// public/js/api.js
const API_BASE = '/api';

const api = {
    token: localStorage.getItem('api_token'),

    setToken(token) {
        this.token = token;
        localStorage.setItem('api_token', token);
    },

    async request(path, method = 'GET', body = null) {
        const headers = {
            'Content-Type': 'application/json'
        };
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }

        const options = { method, headers };
        if (body) options.body = JSON.stringify(body);

        const resp = await fetch(`${API_BASE}${path}`, options);
        const data = await resp.json();

        if (!resp.ok) {
            if (resp.status === 401 || resp.status === 403) {
                // Redirect to login if not already there
                if (!window.location.pathname.includes('login.html')) {
                    // window.location.href = '/login.html';
                }
            }
            throw new Error(data.error || 'API Error');
        }
        return data;
    },

    auth: {
        async login(username, password) {
            const data = await api.request('/auth/login', 'POST', { username, password });
            api.setToken(data.api_token);
            localStorage.setItem('user', JSON.stringify(data));
            return data;
        },
        async signup(username, password) {
            return await api.request('/auth/signup', 'POST', { username, password });
        },
        async me() {
            return await api.request('/auth/me');
        }
    },

    music: {
        async search(term) {
            return await api.request(`/music/search?term=${encodeURIComponent(term)}`);
        },
        async lookup(id) {
            return await api.request(`/music/lookup?id=${id}`);
        },
        async checkProcessed(id) {
            return await api.request(`/music/check-processed?id=${id}`);
        }
    },

    download: {
        async add(trackId) {
            return await api.request('/download/add', 'POST', { trackId });
        },
        async queue() {
            return await api.request('/download/queue');
        },
        async status(id) {
            return await api.request(`/download/status?id=${id}`);
        },
        async statusByTrack(trackId) {
            return await api.request(`/download/status?trackId=${trackId}`);
        }
    },

    admin: {
        async listUsers() {
            return await api.request('/admin/users');
        },
        async userUsage(userId) {
            return await api.request(`/admin/user/usage?user_id=${userId}`);
        },
        async stats() {
            return await api.request('/admin/stats');
        }
    }
};
