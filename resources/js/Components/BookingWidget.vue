<script setup>
import { ref, computed, watch } from 'vue';

const selectedDate = ref('');
const slots = ref([]);
const loadingSlots = ref(false);
const selectedSlot = ref(null);
const name = ref('');
const email = ref('');
const notes = ref('');
const submitting = ref(false);
const success = ref(null);
const error = ref(null);

const minDate = computed(() => {
    const d = new Date();
    return d.toISOString().slice(0, 10);
});

const formattedSlots = computed(() => {
    return slots.value.map((rfc) => {
        const d = new Date(rfc);
        return {
            value: rfc,
            label: d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }),
        };
    });
});

async function fetchSlots() {
    if (!selectedDate.value) {
        slots.value = [];
        selectedSlot.value = null;
        return;
    }
    loadingSlots.value = true;
    error.value = null;
    try {
        const res = await fetch(`/api/availability?date=${selectedDate.value}`);
        const data = await res.json();
        slots.value = data.slots || [];
        selectedSlot.value = null;
    } catch {
        error.value = 'Error al cargar horarios.';
        slots.value = [];
    } finally {
        loadingSlots.value = false;
    }
}

watch(selectedDate, fetchSlots);

async function submit() {
    if (!selectedSlot.value || !name.value.trim()) return;
    submitting.value = true;
    success.value = null;
    error.value = null;
    try {
        const res = await fetch('/api/book', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                start: selectedSlot.value,
                name: name.value.trim(),
                email: email.value.trim() || null,
                notes: notes.value.trim() || null,
            }),
        });
        const data = await res.json();
        if (!res.ok) {
            error.value = data.message || data.errors?.start?.[0] || 'Error al reservar.';
            return;
        }
        success.value = data.message;
        selectedSlot.value = null;
        name.value = '';
        email.value = '';
        notes.value = '';
        fetchSlots();
    } catch {
        error.value = 'Error de conexion.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="booking-widget">
        <h3 class="widget-title">Reservar cita</h3>

        <div class="field">
            <label for="booking-date">Fecha</label>
            <input
                id="booking-date"
                v-model="selectedDate"
                type="date"
                :min="minDate"
                class="input"
            />
        </div>

        <div v-if="loadingSlots" class="loading">Cargando horarios...</div>

        <div v-if="selectedDate && !loadingSlots" class="field">
            <label>Hora</label>
            <div class="slots-grid">
                <button
                    v-for="slot in formattedSlots"
                    :key="slot.value"
                    type="button"
                    class="slot-btn"
                    :class="{ active: selectedSlot === slot.value }"
                    @click="selectedSlot = slot.value"
                >
                    {{ slot.label }}
                </button>
            </div>
            <p v-if="formattedSlots.length === 0" class="no-slots">No hay horarios disponibles.</p>
        </div>

        <div v-if="selectedSlot" class="field">
            <label for="booking-name">Nombre</label>
            <input
                id="booking-name"
                v-model="name"
                type="text"
                class="input"
                placeholder="Tu nombre"
                required
            />
        </div>

        <div v-if="selectedSlot" class="field">
            <label for="booking-email">Email</label>
            <input
                id="booking-email"
                v-model="email"
                type="email"
                class="input"
                placeholder="tu@email.com"
            />
        </div>

        <div v-if="selectedSlot" class="field">
            <label for="booking-notes">Notas (opcional)</label>
            <textarea
                id="booking-notes"
                v-model="notes"
                class="input textarea"
                placeholder="Comentarios..."
                rows="2"
            />
        </div>

        <button
            v-if="selectedSlot"
            type="button"
            class="submit-btn"
            :disabled="!name.trim() || submitting"
            @click="submit"
        >
            {{ submitting ? 'Reservando...' : 'Confirmar reserva' }}
        </button>

        <p v-if="error" class="message error">{{ error }}</p>
        <p v-if="success" class="message success">{{ success }}</p>
    </div>
</template>

<style scoped>
.booking-widget {
    background: rgba(0, 0, 0, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 1.5rem;
    max-width: 360px;
}

.widget-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: white;
    margin: 0 0 1rem 0;
}

.field {
    margin-bottom: 1rem;
}

.field label {
    display: block;
    font-size: 0.875rem;
    color: #9ca3af;
    margin-bottom: 0.25rem;
}

.input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    color: white;
    font-size: 1rem;
}

.input::placeholder {
    color: #6b7280;
}

.textarea {
    resize: vertical;
    min-height: 60px;
}

.slots-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
}

.slot-btn {
    padding: 0.5rem;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    color: white;
    font-size: 0.875rem;
    cursor: pointer;
    transition: background 0.2s, border-color 0.2s;
}

.slot-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.3);
}

.slot-btn.active {
    background: #2563eb;
    border-color: #2563eb;
}

.loading,
.no-slots {
    color: #9ca3af;
    font-size: 0.875rem;
    margin: 0.5rem 0;
}

.submit-btn {
    margin-top: 0.5rem;
    padding: 0.625rem 1.25rem;
    background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
    border: none;
    border-radius: 6px;
    color: white;
    font-weight: 500;
    font-size: 1rem;
    cursor: pointer;
    transition: opacity 0.2s;
}

.submit-btn:hover:not(:disabled) {
    opacity: 0.9;
}

.submit-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.message {
    margin-top: 1rem;
    font-size: 0.875rem;
}

.message.error {
    color: #f87171;
}

.message.success {
    color: #4ade80;
}
</style>
