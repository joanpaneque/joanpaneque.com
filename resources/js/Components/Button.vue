<template>
  <div class="button-wrapper">
    <button class="button">
      <slot />
    </button>
  </div>
</template>

<style scoped>
.button-wrapper {
  display: inline-block;
  background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);
  background-size: 200% 200%;
  border-radius: 8px;
  padding: 1px;
  transition: background-position 0.5s ease;
}

.button-wrapper:hover {
  background-position: 100% 0;
  animation: gradient-shift 2s ease infinite;
}

@keyframes gradient-shift {
  0%, 100% {
    background-position: 0% 50%;
  }
  50% {
    background-position: 100% 50%;
  }
}

.button {
  background: #000;
  border: none;
  border-radius: 7px;
  color: white;
  padding: 0.875rem 2rem;
  cursor: pointer;
  font-family: inherit;
  font-weight: 500;
  font-size: 1rem;
  width: 100%;
  display: block;
  transition: background 0.3s ease;
}

.button-wrapper:hover .button {
  background: #1a1a1a;
}

.button:active {
  background: #0a0a0a;
}
</style>

