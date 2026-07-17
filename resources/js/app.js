import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import relativeTime from './alpine/relative-time';

Alpine.data('relativeTime', relativeTime);

Livewire.start();
