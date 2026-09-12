<script setup>
import {
    AlertCircle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Link2,
    LoaderCircle,
    LogOut,
    Map as MapIcon,
    MapPinned,
    MessageSquare,
    RefreshCw,
    Star,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, ApiError, csrfCookie } from './lib/api';

const user = ref(null);
const booting = ref(true);
const loginBusy = ref(false);
const syncBusy = ref(false);
const reviewsLoading = ref(false);
const error = ref('');
const notice = ref('');

const loginForm = reactive({
    email: 'admin@mail.ru',
    password: '12345678',
});

const sourceUrl = ref('');
const organization = ref(null);
const sync = ref(null);
const reviews = ref([]);
const currentPage = ref(1);
const pagination = reactive({
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
    from: null,
    to: null,
});

let pollTimer = null;

const syncStatus = computed(() => (
    organization.value?.sync_status
    || sync.value?.status
    || 'idle'
));

const isSyncing = computed(() => (
    syncStatus.value === 'queued' || syncStatus.value === 'processing'
));

const syncProgress = computed(() => Math.min(
    100,
    Math.max(
        0,
        Number(sync.value?.progress ?? organization.value?.sync_progress ?? 0),
    ),
));

const ratingText = computed(() => {
    if (organization.value?.rating === null || organization.value?.rating === undefined) {
        return '—';
    }

    return Number(organization.value.rating).toFixed(1).replace('.', ',');
});

const pageNumbers = computed(() => {
    const total = Math.max(1, Number(pagination.last_page || 1));
    const current = currentPage.value;
    let start = Math.max(1, current - 2);
    let end = Math.min(total, start + 4);

    start = Math.max(1, end - 4);

    return Array.from({ length: end - start + 1 }, (_, index) => start + index);
});

const visibleError = computed(() => (
    error.value || organization.value?.last_error || sync.value?.error || ''
));

function emptyPagination() {
    return {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
        from: null,
        to: null,
    };
}

function applyPayload(data, withReviews = true) {
    organization.value = data?.organization ?? null;
    sync.value = data?.sync ?? null;

    if (withReviews) {
        reviews.value = data?.reviews ?? [];
        Object.assign(pagination, data?.pagination ?? emptyPagination());
        currentPage.value = Number(pagination.current_page || 1);
    }

    if (organization.value?.source_url) {
        sourceUrl.value = organization.value.source_url;
    }
}

function setRequestError(requestError, fallback = 'Не удалось выполнить запрос.') {
    if (requestError instanceof ApiError && requestError.message) {
        error.value = requestError.message;
        return;
    }

    error.value = requestError?.message || fallback;
}

function clearMessages() {
    error.value = '';
    notice.value = '';
}

async function loadOrganization(page = 1) {
    const data = await api(`/api/organization?page=${page}`);
    applyPayload(data);

    if (isSyncing.value) {
        startPolling();
    } else {
        stopPolling();
    }
}

async function refreshStatus() {
    if (!user.value || !organization.value) {
        stopPolling();
        return;
    }

    try {
        const data = await api('/api/organization/status');
        applyPayload(data, false);

        if (!isSyncing.value) {
            stopPolling();

            if (syncStatus.value === 'completed' || syncStatus.value === 'failed') {
                await loadOrganization(currentPage.value);
            }
        }
    } catch (requestError) {
        setRequestError(requestError, 'Не удалось обновить статус синхронизации.');
    }
}

function startPolling() {
    stopPolling();
    pollTimer = window.setInterval(refreshStatus, 2500);
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function login() {
    clearMessages();
    loginBusy.value = true;

    try {
        await csrfCookie();
        const data = await api('/api/login', {
            method: 'POST',
            body: loginForm,
        });

        user.value = data.user;
        await loadOrganization();
    } catch (requestError) {
        setRequestError(requestError, 'Не удалось войти.');
    } finally {
        loginBusy.value = false;
    }
}

async function logout() {
    clearMessages();

    try {
        await csrfCookie();
        await api('/api/logout', { method: 'POST' });
    } catch (requestError) {
        setRequestError(requestError, 'Не удалось завершить сессию.');
    } finally {
        stopPolling();
        user.value = null;
        organization.value = null;
        sync.value = null;
        reviews.value = [];
        sourceUrl.value = '';
    }
}

async function saveOrganization() {
    clearMessages();

    if (!sourceUrl.value.trim()) {
        error.value = 'Вставьте ссылку на карточку Яндекс.Карт.';
        return;
    }

    if (isSyncing.value) {
        return;
    }

    syncBusy.value = true;

    try {
        const data = await api('/api/organization', {
            method: 'POST',
            body: {
                source_url: sourceUrl.value.trim(),
            },
        });

        applyPayload(data);
        notice.value = 'Карточка добавлена в очередь. Данные появятся после синхронизации.';
        startPolling();
    } catch (requestError) {
        setRequestError(requestError, 'Не удалось поставить карточку в очередь.');
    } finally {
        syncBusy.value = false;
    }
}

async function loadPage(page) {
    const targetPage = Number(page);

    if (
        reviewsLoading.value
        || targetPage < 1
        || targetPage > Number(pagination.last_page || 1)
        || targetPage === currentPage.value
    ) {
        return;
    }

    reviewsLoading.value = true;

    try {
        const data = await api(`/api/organization/reviews?page=${targetPage}`);
        reviews.value = data.reviews ?? [];
        Object.assign(pagination, data.pagination ?? emptyPagination());
        currentPage.value = Number(pagination.current_page || targetPage);
    } catch (requestError) {
        setRequestError(requestError, 'Не удалось загрузить отзывы.');
    } finally {
        reviewsLoading.value = false;
    }
}

function formatNumber(value) {
    return new Intl.NumberFormat('ru-RU').format(Number(value || 0));
}

function formatDate(value) {
    if (!value) {
        return 'Дата не указана';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Дата не указана';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(date);
}

function formatSyncDate(value) {
    if (!value) {
        return 'Еще не запускалась';
    }

    return formatDate(value);
}

function statusLabel(status = syncStatus.value) {
    return {
        idle: 'Не запускалась',
        queued: 'В очереди',
        processing: 'Синхронизация',
        completed: 'Данные актуальны',
        failed: 'Ошибка',
    }[status] || 'Статус неизвестен';
}

function statusTone(status = syncStatus.value) {
    return {
        idle: 'neutral',
        queued: 'pending',
        processing: 'pending',
        completed: 'success',
        failed: 'danger',
    }[status] || 'neutral';
}

function initials(value) {
    return (value || 'М')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function reviewAuthor(review) {
    return review.author || 'Пользователь Яндекса';
}

function reviewBody(review) {
    return review.body || 'Отзыв без текста';
}

function starIsActive(rating, star) {
    return Number(rating || 0) >= star;
}

onMounted(async () => {
    try {
        const data = await api('/api/me');
        user.value = data.user;
        await loadOrganization();
    } catch (requestError) {
        if (requestError?.status !== 401) {
            setRequestError(requestError, 'Не удалось загрузить рабочее пространство.');
        }
    } finally {
        booting.value = false;
    }
});

onBeforeUnmount(stopPolling);
</script>

<template>
    <div v-if="booting" class="screen-loader">
        <div class="loader-mark"><MapPinned :size="24" /></div>
        <LoaderCircle class="spin" :size="22" />
        <span>Загружаем рабочее пространство</span>
    </div>

    <section v-else-if="!user" class="login-screen">
        <div class="login-aside">
            <div class="brand brand-light">
                <span class="brand-mark"><MapPinned :size="20" /></span>
                <span>Mapline</span>
            </div>

            <div class="login-aside-copy">
                <p class="eyebrow eyebrow-light">Рабочее пространство</p>
                <h1>Карточка бизнеса без ручной рутины.</h1>
                <p>
                    Подключайте организацию на Яндекс.Картах, следите за рейтингом
                    и работайте с отзывами в одном окне.
                </p>
            </div>

        </div>

        <div class="login-content">
            <div class="login-card">
                <div class="login-card-heading">
                    <p class="eyebrow">Вход для команды</p>
                    <h2>С возвращением</h2>
                    <p>Войдите, чтобы открыть подключенные карточки.</p>
                </div>

                <form class="auth-form" @submit.prevent="login">
                    <label class="field">
                        <span>Email</span>
                        <input
                            v-model="loginForm.email"
                            type="email"
                            autocomplete="email"
                            placeholder="you@company.ru"
                            required
                        >
                    </label>

                    <label class="field">
                        <span>Пароль</span>
                        <input
                            v-model="loginForm.password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Введите пароль"
                            required
                        >
                    </label>

                    <div v-if="visibleError" class="notice notice-danger">
                        <AlertCircle :size="18" />
                        <span>{{ visibleError }}</span>
                    </div>

                    <button class="button button-primary button-wide" type="submit" :disabled="loginBusy">
                        <LoaderCircle v-if="loginBusy" class="spin" :size="18" />
                        <span>{{ loginBusy ? 'Проверяем данные...' : 'Войти' }}</span>
                    </button>
                </form>

                <p class="login-hint">Демо-доступ: admin@mail.ru / 12345678</p>
            </div>
        </div>
    </section>

    <div v-else class="app-shell">
        <header class="topbar">
            <div class="topbar-brand">
                <div class="brand">
                    <span class="brand-mark"><MapPinned :size="19" /></span>
                    <span>Mapline</span>
                </div>
                <span class="topbar-divider"></span>
                <span class="topbar-context">Отзывы и карточки</span>
            </div>

            <div class="topbar-actions">
                <div class="user-chip">
                    <span class="avatar avatar-small">{{ initials(user.name) }}</span>
                    <span class="user-chip-copy">
                        <strong>{{ user.name }}</strong>
                        <small>{{ user.email }}</small>
                    </span>
                </div>
                <button class="icon-button" type="button" title="Выйти" @click="logout">
                    <LogOut :size="18" />
                </button>
            </div>
        </header>

        <aside class="sidebar">
            <div class="sidebar-section">
                <p class="sidebar-label">Рабочая область</p>
                <button class="nav-item nav-item-active" type="button">
                    <MapIcon :size="18" />
                    <span>Карточка и отзывы</span>
                </button>
            </div>
        </aside>

        <main class="main-content">
            <div class="content-wrap">
                <div class="page-heading">
                    <div>
                        <p class="eyebrow">Яндекс Карты</p>
                        <h1>Карточка организации</h1>
                        <p class="page-heading-subtitle">
                            Подключите источник, чтобы собирать рейтинг и отзывы в актуальном виде.
                        </p>
                    </div>
                    <div class="live-indicator">
                        <span class="live-dot"></span>
                        <span>Локальное окружение</span>
                    </div>
                </div>

                <div v-if="visibleError" class="notice notice-danger notice-wide">
                    <AlertCircle :size="18" />
                    <span>{{ visibleError }}</span>
                </div>

                <div v-if="notice" class="notice notice-success notice-wide">
                    <CheckCircle2 :size="18" />
                    <span>{{ notice }}</span>
                </div>

                <section class="panel connection-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Источник данных</p>
                            <h2>Ссылка на карточку</h2>
                            <p>Подойдет обычная ссылка из адресной строки Яндекс.Карт.</p>
                        </div>
                        <span class="status-badge" :class="`status-${statusTone()}`">
                            <span class="status-dot"></span>
                            {{ statusLabel() }}
                        </span>
                    </div>

                    <form class="source-form" @submit.prevent="saveOrganization">
                        <label class="source-input">
                            <Link2 :size="19" />
                            <input
                                v-model="sourceUrl"
                                type="url"
                                placeholder="https://yandex.ru/maps/org/..."
                                aria-label="Ссылка на карточку Яндекс.Карт"
                                :disabled="isSyncing"
                            >
                        </label>
                        <button class="button button-primary" type="submit" :disabled="syncBusy || isSyncing">
                            <LoaderCircle v-if="syncBusy" class="spin" :size="18" />
                            <RefreshCw v-else :size="18" />
                            <span>{{ isSyncing ? 'Синхронизация...' : 'Обновить данные' }}</span>
                        </button>
                    </form>

                    <div class="connection-meta">
                        <span>
                            <CheckCircle2 :size="16" />
                            Данные сохраняются в локальной базе
                        </span>
                        <a
                            v-if="organization?.canonical_url"
                            :href="organization.canonical_url"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Открыть источник
                            <ExternalLink :size="14" />
                        </a>
                    </div>
                </section>

                <section v-if="!organization" class="empty-panel">
                    <div class="empty-icon"><MapPinned :size="30" /></div>
                    <p class="eyebrow">Первое подключение</p>
                    <h2>Добавьте карточку, с которой начнем</h2>
                    <p>
                        Вставьте ссылку выше. Сервис проверит домен, поставит задачу в очередь
                        и загрузит доступные данные организации.
                    </p>
                </section>

                <template v-else>
                    <section class="organization-card">
                        <div class="organization-main">
                            <div class="organization-icon"><MapPinned :size="24" /></div>
                            <div class="organization-copy">
                                <div class="organization-title-row">
                                    <h2>{{ organization.name || 'Обрабатываем карточку' }}</h2>
                                    <span class="status-badge" :class="`status-${statusTone()}`">
                                        <span class="status-dot"></span>
                                        {{ statusLabel() }}
                                    </span>
                                </div>
                                <p>{{ organization.address || 'Адрес появится после синхронизации' }}</p>
                                <a
                                    v-if="organization.canonical_url"
                                    :href="organization.canonical_url"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="source-link"
                                >
                                    {{ organization.canonical_url }}
                                    <ExternalLink :size="14" />
                                </a>
                            </div>
                        </div>

                        <div class="rating-summary">
                            <strong>{{ ratingText }}</strong>
                            <div class="rating-stars">
                                <Star
                                    v-for="star in 5"
                                    :key="star"
                                    :size="17"
                                    :fill="starIsActive(organization.rating, star) ? 'currentColor' : 'none'"
                                    :class="{ 'star-muted': !starIsActive(organization.rating, star) }"
                                />
                            </div>
                            <span>средний рейтинг</span>
                        </div>
                    </section>

                    <div v-if="isSyncing" class="sync-progress-panel">
                        <div class="sync-progress-heading">
                            <div>
                                <div class="sync-progress-title">
                                    <LoaderCircle class="spin" :size="17" />
                                    <strong>{{ statusLabel() }}</strong>
                                </div>
                                <span>
                                    Собираем отзывы в фоне. Можно оставить страницу открытой.
                                </span>
                            </div>
                            <strong>{{ syncProgress }}%</strong>
                        </div>
                        <div class="progress-track">
                            <span :style="{ width: `${syncProgress}%` }"></span>
                        </div>
                        <div class="sync-progress-meta">
                            <span v-if="sync?.processed_reviews">
                                Обработано отзывов: {{ formatNumber(sync.processed_reviews) }}
                            </span>
                            <span v-else>Подготавливаем первый запрос к источнику</span>
                            <span v-if="sync?.total_reviews">
                                из {{ formatNumber(sync.total_reviews) }}
                            </span>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon stat-icon-orange"><Star :size="18" /></div>
                            <span class="stat-label">Количество оценок</span>
                            <strong>{{ formatNumber(organization.ratings_count) }}</strong>
                            <small>точное значение источника</small>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon stat-icon-blue"><MessageSquare :size="18" /></div>
                            <span class="stat-label">Количество отзывов</span>
                            <strong>{{ formatNumber(organization.reviews_count) }}</strong>
                            <small>доступно для загрузки</small>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon stat-icon-green"><RefreshCw :size="18" /></div>
                            <span class="stat-label">Последняя синхронизация</span>
                            <strong class="stat-date">{{ formatSyncDate(organization.last_synced_at) }}</strong>
                            <small>{{ isSyncing ? 'обновление выполняется' : 'данные сохранены' }}</small>
                        </div>
                    </div>

                    <section class="panel reviews-panel">
                        <div class="panel-heading reviews-heading">
                            <div>
                                <p class="eyebrow">Модерация и контроль</p>
                                <h2>Отзывы</h2>
                                <p v-if="pagination.total">
                                    Показаны {{ pagination.from }}-{{ pagination.to }} из {{ formatNumber(pagination.total) }}
                                </p>
                                <p v-else>Пока нет сохраненных отзывов</p>
                            </div>
                            <div class="reviews-page-size">
                                <MessageSquare :size="16" />
                                <span>по 50 на страницу</span>
                            </div>
                        </div>

                        <div v-if="reviewsLoading" class="reviews-state">
                            <LoaderCircle class="spin" :size="24" />
                            <span>Загружаем страницу отзывов...</span>
                        </div>

                        <div v-else-if="reviews.length" class="reviews-list">
                            <article v-for="review in reviews" :key="review.id" class="review-item">
                                <div class="review-author">
                                    <img
                                        v-if="review.author_avatar_url"
                                        :src="review.author_avatar_url"
                                        :alt="reviewAuthor(review)"
                                        class="avatar avatar-review"
                                    >
                                    <span v-else class="avatar avatar-review">
                                        {{ initials(reviewAuthor(review)) }}
                                    </span>
                                    <div>
                                        <strong>{{ reviewAuthor(review) }}</strong>
                                        <span>{{ formatDate(review.published_at) }}</span>
                                    </div>
                                </div>
                                <div class="review-body">
                                    <div class="review-rating">
                                        <Star
                                            v-for="star in 5"
                                            :key="star"
                                            :size="15"
                                            :fill="starIsActive(review.rating, star) ? 'currentColor' : 'none'"
                                            :class="{ 'star-muted': !starIsActive(review.rating, star) }"
                                        />
                                        <span>{{ review.rating }}/5</span>
                                    </div>
                                    <p>{{ reviewBody(review) }}</p>
                                </div>
                                <a
                                    v-if="review.source_url"
                                    :href="review.source_url"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="review-source"
                                    title="Открыть отзыв на источнике"
                                >
                                    <ExternalLink :size="15" />
                                </a>
                            </article>
                        </div>

                        <div v-else class="reviews-state reviews-empty">
                            <div class="empty-icon empty-icon-small"><MessageSquare :size="21" /></div>
                            <strong>Отзывов еще нет</strong>
                            <span>
                                После завершения синхронизации здесь появятся доступные отзывы карточки.
                            </span>
                        </div>

                        <div v-if="pagination.last_page > 1" class="pagination">
                            <button
                                class="pagination-button"
                                type="button"
                                :disabled="currentPage <= 1 || reviewsLoading"
                                @click="loadPage(currentPage - 1)"
                            >
                                <ChevronLeft :size="17" />
                            </button>
                            <button
                                v-for="page in pageNumbers"
                                :key="page"
                                class="pagination-button"
                                :class="{ 'pagination-button-active': page === currentPage }"
                                type="button"
                                :disabled="reviewsLoading"
                                @click="loadPage(page)"
                            >
                                {{ page }}
                            </button>
                            <button
                                class="pagination-button"
                                type="button"
                                :disabled="currentPage >= pagination.last_page || reviewsLoading"
                                @click="loadPage(currentPage + 1)"
                            >
                                <ChevronRight :size="17" />
                            </button>
                        </div>
                    </section>
                </template>
            </div>
        </main>
    </div>
</template>
