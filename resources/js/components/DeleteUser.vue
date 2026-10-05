<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// 刪除帳號是匿名化（App\Auth\AccountDeletion、docs/SPEC.md 第 5 節）。
// 老師用 Google 登入，沒有密碼可以確認，改成輸入自己的 email。
defineProps<{ email: string }>();
</script>

<template>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="刪除帳號"
            description="刪除你的帳號、私人題組與活動"
        />
        <div
            class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10"
        >
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">警告</p>
                <p class="text-sm">請謹慎操作，刪除後無法復原。</p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" data-test="delete-user-button"
                        >刪除帳號</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>確定要刪除帳號嗎？</DialogTitle>
                            <DialogDescription class="space-y-2">
                                <span class="block"
                                    >你的名字、email
                                    與登入方式會被清除，之後不能再用這個帳號登入。</span
                                >
                                <span class="block"
                                    >活動連結立刻失效；私人題組、活動與學生的作答在
                                    30
                                    天後永久刪除。已經公開到共備庫的題組會留下，照舊署名你的名字。</span
                                >
                                <span class="block"
                                    >請輸入你的 email（{{ email }}）確認。</span
                                >
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="confirmation" class="sr-only"
                                >你的 email</Label
                            >
                            <Input
                                id="confirmation"
                                name="confirmation"
                                autocomplete="off"
                                :placeholder="email"
                            />
                            <InputError :message="errors.confirmation" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    variant="secondary"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    取消
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                刪除帳號
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
