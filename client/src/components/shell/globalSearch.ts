export type GlobalSearchSelection = {
  destinationId: string;
  focus?: {
    conversationId: string;
    messageId: string;
    threadRootMessageId: string | null;
  };
};
