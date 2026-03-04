<script setup>
/**
 * SHOWCASE: Frontend Clean Architecture & Vue 3 Mastery
 *
 * @challenge Keeping complex frontend views readable and maintainable while handling data filtering, modal states, and CRUD operations.
 * @solution Utilized Vue 3 Composition API with custom Composables to extract business logic. Leveraged Inertia.js for seamless SPA navigation and state preservation.
 * @highlight Demonstrates component-driven design, reactivity management (toRefs), and optimized Inertia requests.
 */

import { ref, toRefs } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// Sub-components
import EmptyVisionState from '@/Components/Goals/EmptyVisionState.vue';
import VisionHeader from '@/Components/Goals/VisionHeader.vue';
import AreaFilter from '@/Components/Goals/AreaFilter.vue';
import GoalGrid from '@/Components/Goals/GoalGrid.vue';
import StrategyPickerModal from '@/Components/Goals/Modals/StrategyPickerModal.vue';
import DangerModal from '@/Components/DangerModal.vue';

// Extracted Business Logic (Composables)
import { useGoalFiltering } from '@/Composables/useGoalFiltering';
import { useDeleteAction } from '@/Composables/useDeleteAction';

const props = defineProps({
    identity: Object,
    allVisions: Array,
    areas: Array,
    goals: Array,
    generalTasks: Array,
    generalHabits: Array
});

// Convert props to refs to maintain reactivity inside Composables
const { goals, areas } = toRefs(props);

// 1. Filtering Logic
const { activeAreaId, filteredGoals, activeAreaName } = useGoalFiltering(goals, areas);

// 2. Generic Deletion Logic
const { 
    showModal: showDeleteModal, 
    itemToDelete: goalToDelete, 
    isProcessing: isDeleting, 
    confirmDeletion: requestDelete, 
    executeDelete 
} = useDeleteAction();

const handleDeleteConfirm = () => {
    executeDelete(route('goals.delete-mid', goalToDelete.value.id));
};

// 3. Local UI State (Modals & Navigation)
const showStrategyModal = ref(false);
const strategyLevel = ref('top');    
const strategyParentId = ref(null);  

const openStrategyModal = (level, parentId = null) => {
    strategyLevel.value = level;
    strategyParentId.value = parentId;
    showStrategyModal.value = true;
};

const handleManualCreation = () => {
    showStrategyModal.value = false;
    
    if (strategyLevel.value === 'top') {
        router.get(route('goals.create-vision'));
    } else {
        router.get(route('goals.create-mid'), { vision_id: strategyParentId.value });
    }
};

const handleSwitchVision = (id) => {
    // Optimized Inertia request: fetches only the needed props without reloading the page
    router.get(route('goals.index'), { vision_id: id }, {
        preserveState: true,
        preserveScroll: true,
        only: ['identity', 'goals']
    });
};
</script>

<template>
    <Head :title="$t('goals.index.title')" />

    <AuthenticatedLayout>
        <template #header>{{ $t('goals.index.title') }}</template>

        <div class="max-w-7xl mx-auto py-6 space-y-8">
            
            <div v-if="identity">
                <VisionHeader 
                    :vision="identity" 
                    :all-visions="allVisions"
                    @switch="handleSwitchVision"
                    @create-vision="openStrategyModal('top')"
                />

                <div class="flex gap-2 items-center mb-3 mt-8 px-1">
                    <span class="bg-brand-blue dark:bg-blue-600 font-bold px-2 py-1 rounded shadow-sm text-[10px] text-white tracking-widest uppercase">
                        {{ $t('goals.index.how_to_achieve') }}
                    </span>
                    <div class="bg-gray-200 dark:bg-gray-700 flex-1 h-px"></div>
                </div>
                
                <AreaFilter 
                    :areas="areas" 
                    v-model="activeAreaId" 
                />

                <div class="mt-6">
                    <GoalGrid 
                        :goals="filteredGoals" 
                        :vision-id="identity.id"
                        :active-area-name="activeAreaName"
                        @delete="requestDelete"
                        @create-mid="(id) => openStrategyModal('mid', id)"
                    />
                </div>
            </div>

            <div v-else class="mt-6 animate-fade-in-up">
                <EmptyVisionState @create="openStrategyModal('top')" />
            </div>
        </div>

        <StrategyPickerModal 
            :show="showStrategyModal"
            :level="strategyLevel"
            :parent-id="strategyParentId"
            @close="showStrategyModal = false"
            @manual="handleManualCreation"
        />

        <DangerModal 
            :show="showDeleteModal"
            :title="$t('goals.modals.delete_mid.title')"
            :message="$t('goals.modals.delete_mid.message', { name: goalToDelete?.name || '' })"
            :confirm-text="goalToDelete?.name || ''"
            :processing="isDeleting"
            @close="showDeleteModal = false"
            @confirm="handleDeleteConfirm"
        />

    </AuthenticatedLayout>
</template>