// public/js/app.js
const app = {
    user: JSON.parse(localStorage.getItem('user')),

    init() {
        if (this.user) {
            this.updateAuthUI();
        }
        this.showPage('search');

        // Listen for search enter
        document.getElementById('search-input')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') this.search();
        });
    },

    updateAuthUI() {
        const authLinks = document.getElementById('auth-links');
        const userInfo = document.getElementById('user-info');
        const usernameDisplay = document.getElementById('username-display');
        const adminLink = document.getElementById('admin-link');

        if (this.user) {
            authLinks.classList.add('d-none');
            userInfo.classList.remove('d-none');
            usernameDisplay.textContent = this.user.username;
            if (this.user.role === 'admin') {
                adminLink.classList.remove('d-none');
            }
        } else {
            authLinks.classList.remove('d-none');
            userInfo.classList.add('d-none');
            adminLink.classList.add('d-none');
        }
    },

    showPage(pageId) {
        document.querySelectorAll('.page').forEach(p => p.classList.add('d-none'));
        document.getElementById(`page-${pageId}`).classList.remove('d-none');

        if (pageId === 'queue') this.loadQueue();
        if (pageId === 'admin') this.loadAdmin();
    },

    async handleLogin() {
        const u = document.getElementById('login-username').value;
        const p = document.getElementById('login-password').value;
        try {
            this.user = await api.auth.login(u, p);
            this.updateAuthUI();
            this.showPage('search');
        } catch (e) {
            alert(e.message);
        }
    },

    async handleSignup() {
        const u = document.getElementById('signup-username').value;
        const p = document.getElementById('signup-password').value;
        try {
            await api.auth.signup(u, p);
            alert('Signup successful! Please login.');
            this.showPage('login');
        } catch (e) {
            alert(e.message);
        }
    },

    logout() {
        localStorage.removeItem('user');
        localStorage.removeItem('api_token');
        this.user = null;
        api.token = null;
        this.updateAuthUI();
        this.showPage('login');
    },

    async search() {
        const term = document.getElementById('search-input').value;
        if (!term) return;
        const resultsDiv = document.getElementById('search-results');
        resultsDiv.innerHTML = '<div class="text-center w-100 p-5"><div class="spinner-border text-primary"></div></div>';

        try {
            const data = await api.music.search(term);
            resultsDiv.innerHTML = '';
            data.results.forEach(item => {
                if (item.wrapperType === 'track') {
                    resultsDiv.innerHTML += this.renderTrackCard(item);
                } else if (item.wrapperType === 'collection') {
                    resultsDiv.innerHTML += this.renderCollectionCard(item);
                }
            });
        } catch (e) {
            resultsDiv.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
        }
    },

    renderTrackCard(track) {
        const isDownloaded = track.downloaded;
        const btnText = isDownloaded ? '<i class="bi bi-download"></i> Download' : '<i class="bi bi-cloud-arrow-down"></i> Download';
        const btnClass = isDownloaded ? 'btn-success' : 'btn-primary';

        return `
            <div class="col-md-4 col-lg-3">
                <div class="card h-100">
                    <img src="${track.artworkUrl100.replace('100x100', '400x400')}" class="card-img-top" alt="...">
                    <div class="card-body">
                        <h6 class="card-title text-truncate">${track.trackName}</h6>
                        <p class="card-text small text-muted text-truncate">${track.artistName}</p>
                        <button class="btn btn-sm ${btnClass} w-100" onclick="app.requestDownload('${track.trackId}')">
                            ${btnText}
                        </button>
                    </div>
                </div>
            </div>
        `;
    },

    renderCollectionCard(col) {
        return `
            <div class="col-md-4 col-lg-3">
                <div class="card h-100 border-info">
                    <img src="${col.artworkUrl100.replace('100x100', '400x400')}" class="card-img-top" alt="...">
                    <div class="card-body">
                        <h6 class="card-title text-truncate">${col.collectionName}</h6>
                        <p class="card-text small text-muted text-truncate">${col.artistName}</p>
                        <button class="btn btn-sm btn-outline-info w-100" onclick="app.viewCollection('${col.collectionId}')">View Tracks</button>
                    </div>
                </div>
            </div>
        `;
    },

    async requestDownload(trackId) {
        if (!this.user) {
            alert('Please login to download');
            this.showPage('login');
            return;
        }

        try {
            const res = await api.download.add(trackId);
            if (res.status === 'completed') {
                // If already processed, show download link or redirect to detail
                this.viewTrack(trackId);
            } else {
                alert('Added to download queue!');
                this.showPage('queue');
            }
        } catch (e) {
            alert(e.message);
        }
    },

    async viewTrack(trackId) {
        this.showPage('track');
        const container = document.getElementById('track-detail-content');
        container.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary"></div></div>';

        try {
            const data = await api.music.lookup(trackId);
            const track = data.results[0];
            const q = await api.download.statusByTrack(trackId);
            const download = q.download;

            container.innerHTML = `
                <div class="row pt-4">
                    <div class="col-md-4">
                        <img src="${track.artworkUrl100.replace('100x100', '600x600')}" class="img-fluid rounded shadow" alt="...">
                    </div>
                    <div class="col-md-8">
                        <h2>${track.trackName}</h2>
                        <h4 class="text-muted">${track.artistName}</h4>
                        <hr class="border-secondary">
                        <div class="mb-4">
                            <h5>Download Status</h5>
                            <div class="progress mb-2" style="height: 25px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                                     style="width: ${download ? download.progress : 0}%">
                                     ${download ? download.progress : 0}%
                                </div>
                            </div>
                            <div id="download-steps" class="mt-3">
                                ${this.renderDownloadSteps(download)}
                            </div>
                        </div>
                        ${download && download.status === 'completed' ? `
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill me-2"></i> Track is ready!
                                <a href="${download.filePath}" class="btn btn-success ms-3" target="_blank">Download MP3</a>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;

            // Poll for updates if still downloading
            if (download && download.status !== 'completed' && download.status !== 'failed') {
                setTimeout(() => this.viewTrack(trackId), 3000);
            }

        } catch (e) {
            container.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
        }
    },

    renderDownloadSteps(d) {
        if (!d) return '<p class="text-muted">Not in queue</p>';
        const steps = [
            { id: 'pending', label: 'Queued' },
            { id: 'metadata', label: 'Fetching Metadata' },
            { id: 'lyrics', label: 'Crawling Lyrics' },
            { id: 'artwork', label: 'Processing Artwork' },
            { id: 'downloading', label: 'Downloading Audio' },
            { id: 'completed', label: 'Finished' }
        ];

        let html = '';
        let reachedCurrent = false;
        steps.forEach(s => {
            const isActive = d.status_step === s.id || (d.status === 'completed' && s.id === 'completed');
            const isDone = reachedCurrent === false && !isActive;
            if (isActive) reachedCurrent = true;

            html += `
                <div class="download-step ${isActive ? 'active' : ''} ${isDone ? 'text-success' : 'text-muted'}">
                    <i class="bi ${isDone ? 'bi-check-circle-fill' : (isActive ? 'bi-arrow-right-circle-fill' : 'bi-circle')} me-2"></i>
                    ${s.label}
                </div>
            `;
        });
        return html;
    },

    async loadQueue() {
        const tbody = document.getElementById('queue-list');
        tbody.innerHTML = '<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm"></div></td></tr>';

        try {
            const data = await api.download.queue();
            tbody.innerHTML = '';
            data.items.forEach(item => {
                tbody.innerHTML += `
                    <tr>
                        <td><a href="#" onclick="app.viewTrack('${item.trackId}')" class="text-info text-decoration-none">${item.trackId}</a></td>
                        <td><span class="badge bg-secondary">${item.status}</span></td>
                        <td>
                            <div class="progress">
                                <div class="progress-bar" style="width: ${item.progress}%"></div>
                            </div>
                        </td>
                        <td>${new Date(item.addedAt).toLocaleString()}</td>
                        <td><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></td>
                    </tr>
                `;
            });
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-danger">${e.message}</td></tr>`;
        }
    },

    async loadAdmin() {
        const statsDiv = document.getElementById('admin-stats');
        const usersTbody = document.getElementById('admin-users-list');

        try {
            const statsData = await api.admin.stats();
            const s = statsData.stats;
            statsDiv.innerHTML = `
                <div class="col-md-3"><div class="card p-3 text-center"><h5>Users</h5><h3>${s.total_users}</h3></div></div>
                <div class="col-md-3"><div class="card p-3 text-center"><h5>Total Tracks</h5><h3>${s.total_tracks}</h3></div></div>
                <div class="col-md-3"><div class="card p-3 text-center"><h5>Downloaded</h5><h3>${s.downloaded_tracks}</h3></div></div>
                <div class="col-md-3"><div class="card p-3 text-center"><h5>Queue</h5><h3>${s.queue_size}</h3></div></div>
            `;

            const usersData = await api.admin.listUsers();
            usersTbody.innerHTML = '';
            usersData.users.forEach(u => {
                usersTbody.innerHTML += `
                    <tr>
                        <td>${u.id}</td>
                        <td>${u.username}</td>
                        <td><span class="badge bg-info">${u.role}</span></td>
                        <td>${new Date(u.created_at).toLocaleDateString()}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="app.viewUserUsage(${u.id})">Usage</button>
                        </td>
                    </tr>
                `;
            });
        } catch (e) {
            alert(e.message);
        }
    },

    async viewUserUsage(userId) {
        try {
            const data = await api.admin.userUsage(userId);
            let msg = 'User Accesses:\n';
            data.usage.forEach(row => msg += `- ${row.action}: ${row.count}\n`);
            msg += '\nApplications:\n';
            data.applications.forEach(app => msg += `- ${app.name} (${app.type}): ${app.api_token}\n`);
            alert(msg);
        } catch (e) {
            alert(e.message);
        }
    }
};

window.onload = () => app.init();
